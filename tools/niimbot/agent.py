from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from urllib.parse import urlparse
from pathlib import Path

from label_renderer import render_label


BASE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = BASE_DIR.parent.parent
CONFIG_FILE = BASE_DIR / "agent-config.json"
DRIVER_DIR = BASE_DIR / "niimprintx"

POLL_INTERVAL = 5
REQUEST_TIMEOUT = 30
PRINT_TIMEOUT = 120

PRINTER_MODEL = "b1"
PRINTER_CONNECTION = "usb"
PRINTER_PORT = "COM3"
PRINTER_DENSITY = "4"


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

    if not device_id or len(device_id) > 100:
        raise RuntimeError("Device ID is missing or invalid.")

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
        with urllib.request.urlopen(
            request,
            timeout=REQUEST_TIMEOUT,
        ) as response:
            response_body = response.read()

    except urllib.error.HTTPError as error:
        raise RuntimeError(
            f"API returned HTTP {error.code}."
        ) from None

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


def claim_job(config):
    result = api_request(
        config,
        "/api/niimbot_claim.php",
        {},
    )

    data = result.get("data")

    if not isinstance(data, dict):
        raise RuntimeError("Claim response is missing job data.")

    return data.get("job")


def report_job(config, job_id, status, error_message=""):
    payload = {
        "job_id": int(job_id),
        "status": status,
    }

    if status == "failed":
        payload["error_message"] = str(error_message)[:1000]

    result = api_request(
        config,
        "/api/niimbot_report.php",
        payload,
    )

    print(
        f"Job {job_id}: {result.get('message', 'Status reported.')}",
        flush=True,
    )


def print_job(job):
    job_id = job.get("id")

    if not isinstance(job_id, int) or job_id <= 0:
        raise RuntimeError("Claimed job has an invalid ID.")

    print(f"Job {job_id}: preparing label.", flush=True)

    with tempfile.TemporaryDirectory(prefix="niimbot-") as temp_dir:
        label_path = Path(temp_dir) / "asset-label.png"

        render_label(job, str(label_path))

        if not label_path.is_file():
            raise RuntimeError("Label image was not generated.")

        if label_path.stat().st_size == 0:
            raise RuntimeError("Generated label image is empty.")

        env = os.environ.copy()

        existing_pythonpath = env.get("PYTHONPATH", "")
        env["PYTHONPATH"] = (
            str(DRIVER_DIR)
            + (os.pathsep + existing_pythonpath if existing_pythonpath else "")
        )

        command = [
            sys.executable,
            "-m",
            "niimprint",
            "--model", PRINTER_MODEL,
            "--conn", PRINTER_CONNECTION,
            "--addr", PRINTER_PORT,
            "--density", PRINTER_DENSITY,
            "--image", str(label_path),
        ]

        print(
            f"Job {job_id}: sending label to NIIMBOT B1 on {PRINTER_PORT}.",
            flush=True,
        )

        result = subprocess.run(
            command,
            cwd=str(PROJECT_ROOT),
            env=env,
            capture_output=True,
            text=True,
            timeout=PRINT_TIMEOUT,
            check=False,
        )

        if result.returncode != 0:
            details = (result.stderr or result.stdout or "").strip()
            details = details[-700:]

            raise RuntimeError(
                f"Printer command failed with exit code "
                f"{result.returncode}. {details}"
            )

    print(
        f"Job {job_id}: printer command completed successfully.",
        flush=True,
    )


def process_job(config, job):
    if not isinstance(job, dict):
        raise RuntimeError("The claimed job is invalid.")

    job_id = job.get("id")

    if not isinstance(job_id, int) or job_id <= 0:
        raise RuntimeError("The claimed job has an invalid ID.")

    try:
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
            f"Job {job_id}: printing command completed, "
            f"but status reporting failed: {error}",
            flush=True,
        )
        return False

    return True


def main():
    parser = argparse.ArgumentParser(
        description="AssetSys local NIIMBOT print queue agent."
    )

    parser.add_argument(
        "--check-config",
        action="store_true",
        help="Validate local settings without contacting the API or printer.",
    )

    parser.add_argument(
        "--once",
        action="store_true",
        help="Check for one job and exit.",
    )

    args = parser.parse_args()

    try:
        config = load_config()
    except Exception as error:
        print(f"Configuration error: {error}", file=sys.stderr)
        return 1

    print("AssetSys NIIMBOT Agent", flush=True)
    print(f"Device ID: {config['device_id']}", flush=True)
    print(f"API: {config['api_base_url']}", flush=True)
    print(f"Printer: NIIMBOT B1 / {PRINTER_PORT}", flush=True)

    if args.check_config:
        print("Configuration check passed. No job was claimed or printed.")
        return 0

    print("Starting queue polling.", flush=True)

    while True:
        try:
            job = claim_job(config)

            if job is None:
                if args.once:
                    print("No pending print jobs.")
                    return 0

                time.sleep(POLL_INTERVAL)
                continue

            process_job(config, job)

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


if __name__ == "__main__":
    raise SystemExit(main())
