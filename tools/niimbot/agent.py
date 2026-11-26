from __future__ import annotations

import argparse
import builtins
import json
import logging
from logging.handlers import RotatingFileHandler
import re
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urlparse

from PIL import Image

from label_renderer import render_label


BASE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = BASE_DIR.parent.parent
CONFIG_FILE = BASE_DIR / "agent-config.json"
DRIVER_DIR = BASE_DIR / "niimprintx"

if str(DRIVER_DIR) not in sys.path:
    sys.path.insert(0, str(DRIVER_DIR))

try:
    from niimprint import PrinterClient, SerialTransport
except ImportError as error:
    raise RuntimeError(
        "The local niimprint driver is not installed. Follow tools/niimbot/README.md."
    ) from error


POLL_INTERVAL = 3
REQUEST_TIMEOUT = 30
BATCH_SIZE = 5
PRINTER_PORT = "COM3"
PRINTER_DENSITY = 4

# Keep one serial transport/client alive for the entire agent session.
# The patched B1 V2 print_image() sequence is reused for each label.
printer_transport = None
printer_client = None
logger = None
instance_lock_file = None


def setup_logging():
    global logger

    log_dir = BASE_DIR / "logs"
    log_dir.mkdir(parents=True, exist_ok=True)
    log_path = log_dir / "niimbot-agent.log"

    logger = logging.getLogger("assetsys.niimbot")
    logger.setLevel(logging.INFO)
    logger.handlers.clear()

    handler = RotatingFileHandler(
        log_path,
        maxBytes=5 * 1024 * 1024,
        backupCount=5,
        encoding="utf-8",
    )
    handler.setFormatter(logging.Formatter(
        "%(asctime)s [%(levelname)s] %(message)s"
    ))
    logger.addHandler(handler)


def print(*args, **kwargs):
    message = kwargs.get("sep", " ").join(str(arg) for arg in args)
    target = kwargs.get("file")
    level = logging.ERROR if target is sys.stderr else logging.INFO

    if logger is not None:
        logger.log(level, message.rstrip())

    stream = target if target is not None else sys.stdout
    if stream is not None:
        builtins.print(*args, **kwargs)


def acquire_instance_lock():
    global instance_lock_file

    lock_path = BASE_DIR / "logs" / "agent.lock"
    instance_lock_file = lock_path.open("a+b")

    try:
        instance_lock_file.seek(0, 2)
        if instance_lock_file.tell() == 0:
            instance_lock_file.write(b"0")
            instance_lock_file.flush()
        instance_lock_file.seek(0)

        if os.name == "nt":
            import msvcrt
            msvcrt.locking(instance_lock_file.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(instance_lock_file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)

        return True
    except (OSError, BlockingIOError):
        instance_lock_file.close()
        instance_lock_file = None
        return False


def release_instance_lock():
    global instance_lock_file

    if instance_lock_file is None:
        return

    try:
        instance_lock_file.seek(0)
        if os.name == "nt":
            import msvcrt
            msvcrt.locking(instance_lock_file.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            fcntl.flock(instance_lock_file.fileno(), fcntl.LOCK_UN)
    except OSError:
        pass
    finally:
        instance_lock_file.close()
        instance_lock_file = None


def load_config():
    if not CONFIG_FILE.is_file():
        raise RuntimeError("Agent configuration file is missing.")

    with CONFIG_FILE.open("r", encoding="utf-8") as file:
        config = json.load(file)

    api_base_url = str(config.get("api_base_url", "")).rstrip("/")
    token = str(config.get("agent_token", ""))
    device_id = str(config.get("device_id", ""))

    parsed_url = urlparse(api_base_url)
    is_https = parsed_url.scheme == "https"
    is_local_http = (
        parsed_url.scheme == "http"
        and parsed_url.hostname in {"localhost", "127.0.0.1"}
    )

    if not (is_https or is_local_http):
        raise RuntimeError(
            "API must use HTTPS, except for localhost development."
        )

    if not re.fullmatch(r"[a-fA-F0-9]{64}", token):
        raise RuntimeError("Agent token is missing or invalid.")

    if device_id not in {"property-custodian-b1", "it-laptop-b1"}:
        raise RuntimeError("Device ID is missing or not registered for batch printing.")

    return {
        "api_base_url": api_base_url,
        "agent_token": token,
        "device_id": device_id,
    }


def api_request(config, endpoint, payload):
    url = config["api_base_url"] + endpoint
    body = json.dumps(payload).encode("utf-8")

    request = urllib.request.Request(
        url,
        data=body,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": "Bearer " + config["agent_token"],
            "X-NIIMBOT-Device-ID": config["device_id"],
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=REQUEST_TIMEOUT) as response:
            response_body = response.read()
    except urllib.error.HTTPError as error:
        raise RuntimeError(f"API returned HTTP {error.code}.") from None
    except urllib.error.URLError as error:
        raise RuntimeError(
            f"Unable to connect to the AssetSys API: {error.reason}"
        ) from None

    try:
        result = json.loads(response_body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise RuntimeError("API returned an invalid JSON response.") from None

    if not isinstance(result, dict):
        raise RuntimeError("API returned an unexpected response.")

    if not result.get("success"):
        message = result.get("message", "API request failed.")
        raise RuntimeError(str(message)[:300])

    return result


def claim_batch(config):
    result = api_request(config, "/api/niimbot_claim.php", {})
    data = result.get("data")

    if not isinstance(data, dict):
        raise RuntimeError("Claim response is missing batch data.")

    jobs = data.get("jobs", [])
    if not isinstance(jobs, list):
        raise RuntimeError("Claim response contains an invalid job batch.")

    return jobs


def renew_leases(config):
    result = api_request(config, "/api/niimbot_heartbeat.php", {})
    print(f"Lease heartbeat: {result.get('message', 'renewed')}", flush=True)


def report_job(config, job_id, status, error_message=""):
    payload = {
        "job_id": int(job_id),
        "status": status,
    }

    if status == "failed":
        payload["error_message"] = str(error_message)[:1000]

    last_error = None
    for attempt in range(1, 4):
        try:
            result = api_request(config, "/api/niimbot_report.php", payload)
            print(
                f"Job {job_id}: {result.get('message', 'Status reported.')}",
                flush=True,
            )
            return
        except Exception as error:
            last_error = error
            if status != "completed" or attempt == 3:
                raise
            wait_seconds = attempt * 2
            print(
                f"Job {job_id}: completion report attempt {attempt} failed; "
                f"retrying in {wait_seconds}s: {error}",
                flush=True,
            )
            time.sleep(wait_seconds)

    raise RuntimeError(f"Unable to report job status: {last_error}")


def close_printer():
    global printer_transport, printer_client

    if printer_transport is not None:
        try:
            serial_port = getattr(printer_transport, "_serial", None)
            if serial_port is not None and serial_port.is_open:
                serial_port.close()
        except Exception:
            pass

    printer_transport = None
    printer_client = None


def get_printer():
    global printer_transport, printer_client

    serial_port = (
        getattr(printer_transport, "_serial", None)
        if printer_transport is not None
        else None
    )

    if printer_client is not None and serial_port is not None and serial_port.is_open:
        return printer_client

    close_printer()
    printer_transport = SerialTransport(port=PRINTER_PORT)
    printer_client = PrinterClient(printer_transport)
    return printer_client


def print_job(job):
    job_id = job.get("id")

    if not isinstance(job_id, int) or job_id <= 0:
        raise RuntimeError("Claimed job has an invalid ID.")

    print(f"Job {job_id}: preparing label.", flush=True)

    with tempfile.TemporaryDirectory(prefix="niimbot-") as temp_dir:
        label_path = Path(temp_dir) / "asset-label.png"
        render_label(job, str(label_path))

        if not label_path.is_file() or label_path.stat().st_size == 0:
            raise RuntimeError("Generated label image is missing or empty.")

        try:
            printer = get_printer()
            with Image.open(label_path) as image:
                printer.print_image(image, density=PRINTER_DENSITY)
        except Exception:
            # Release a possibly stale serial handle. The next label will
            # establish a fresh connection rather than reuse a broken port.
            close_printer()
            raise

    print(f"Job {job_id}: printer command completed successfully.", flush=True)


def process_job(config, job, index, total):
    if not isinstance(job, dict):
        print(f"Batch item {index}/{total}: invalid job payload; skipped.", flush=True)
        return False

    job_id = job.get("id")

    if not isinstance(job_id, int) or job_id <= 0:
        print(f"Batch item {index}/{total}: invalid job ID; skipped.", flush=True)
        return False

    print(f"Batch progress: {index}/{total} (job {job_id}).", flush=True)

    try:
        # Renew all jobs claimed by this station before each physical print.
        # This keeps later items in a slow batch from expiring while earlier
        # labels are being printed.
        renew_leases(config)
        print_job(job)
    except Exception as error:
        message = str(error)[:1000]
        print(f"Job {job_id}: FAILED - {message}", flush=True)

        try:
            report_job(config, job_id, "failed", message)
        except Exception as report_error:
            print(
                f"Job {job_id}: unable to report failure: {report_error}",
                flush=True,
            )

        return False

    try:
        report_job(config, job_id, "completed")
    except Exception as error:
        print(
            f"Job {job_id}: printed, but status reporting failed: {error}",
            flush=True,
        )
        return False

    return True


def process_batch(config, jobs):
    total = len(jobs)

    if total == 0:
        print("No pending print jobs for this station.", flush=True)
        return 0

    print(f"Claimed {total} print job(s). Starting batch.", flush=True)
    succeeded = 0
    failed = 0

    for index, job in enumerate(jobs, start=1):
        if process_job(config, job, index, total):
            succeeded += 1
        else:
            failed += 1

    print(
        f"Batch finished: {succeeded} completed, {failed} failed "
        f"out of {total}.",
        flush=True,
    )
    return succeeded


def main():
    parser = argparse.ArgumentParser(
        description="AssetSys local NIIMBOT B1 batch print queue agent."
    )

    parser.add_argument(
        "--check-config",
        action="store_true",
        help="Validate local settings without contacting the API or printer.",
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Claim and process one batch, then exit.",
    )

    args = parser.parse_args()

    setup_logging()

    try:
        config = load_config()
    except Exception as error:
        print(f"Configuration error: {error}", file=sys.stderr)
        return 1

    if not acquire_instance_lock():
        print("Another NIIMBOT agent instance is already running for this station. Exiting to prevent duplicate claims.", flush=True)
        return 1

    print("AssetSys NIIMBOT Agent", flush=True)
    print(f"Device ID: {config['device_id']}", flush=True)
    print(f"API: {config['api_base_url']}", flush=True)
    print(f"Printer: NIIMBOT B1 / {PRINTER_PORT}", flush=True)
    print(f"Batch size: up to {BATCH_SIZE} labels", flush=True)
    print("Printer connection: persistent USB session", flush=True)

    if args.check_config:
        print("Configuration check passed. No job was claimed or printed.")
        return 0

    print("Starting queue polling.", flush=True)

    try:
        while True:
            try:
                jobs = claim_batch(config)

                if not jobs:
                    if args.once:
                        print("No pending print jobs.")
                        return 0

                    time.sleep(POLL_INTERVAL)
                    continue

                process_batch(config, jobs)

                if args.once:
                    return 0

            except KeyboardInterrupt:
                print("\nAgent stopped.", flush=True)
                return 0
            except Exception as error:
                print(f"Agent error: {error}", flush=True)

                if args.once:
                    return 1

                time.sleep(POLL_INTERVAL)
    finally:
        close_printer()
        release_instance_lock()


if __name__ == "__main__":
    raise SystemExit(main())
