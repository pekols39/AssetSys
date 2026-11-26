# NIIMBOT B1 Direct Printing — AssetSys

## Purpose

AssetSys queues QR inventory label requests in MySQL. A local Python agent claims jobs assigned to its authenticated station, renders the label, and sends it directly to a NIIMBOT B1 printer over USB.

## Requirements

- Windows with XAMPP and PHP 8.2+
- Python 3.11
- NIIMBOT B1 connected by USB
- The printer's correct serial port (currently configured as `COM3`)
- Composer dependencies installed for AssetSys

## Local driver setup

The third-party `niimprintx` repository and Python virtual environment are local tooling. They are intentionally excluded from the AssetSys Git repository.

1. Clone the upstream driver into `tools/niimbot/niimprintx`.
2. Check out the tested revision `be39f68`.
3. Create a Python 3.11 virtual environment at `tools/niimbot/.venv311`.
4. Install the driver's requirements and install the project in editable mode.
5. Apply `patches/b1-v2-printing.patch` from inside the driver repository.

The patch adapts the print sequence for the B1 V2 setup. It sends a B1 wake-up packet, uses the B1-specific start-print payload and dimensions, sends the image rows, and ends the page. After a two-second processing allowance, it requires a positive `END_PRINT` acknowledgement, polling at 100 ms intervals for up to 30 seconds. A missing, unsupported, or negative acknowledgement raises an error so the agent does not report the job as completed; the physical outcome must then be checked manually. This is a fail-closed completion check, not proof that the label is scannable.

Review the upstream repository's license and instructions before installing or redistributing its source.

## AssetSys configuration

The current local agent imports the driver directly and keeps one USB serial connection open while processing each batch. It reuses the tested B1 V2 `PrinterClient.print_image()` implementation for each label rather than starting a new Python process and reopening COM3 for every job. If a print fails, the agent closes the serial connection and reconnects before the next job.

The agent claims up to five jobs at a time. Jobs are printed sequentially and reported individually. If a job fails or its completion cannot be confirmed, the agent stops the batch and returns only the later, unstarted jobs to `pending`; it does not retry the uncertain job. Released jobs have their claim attempt counter decremented because no print was attempted. If the release API call fails, the remaining jobs retain their leases and will require manual review after expiry.

Each claim receives a 15-minute lease. Before printing every item, the agent calls the authenticated heartbeat endpoint to extend all active leases owned by that station. This prevents later jobs in a slow batch from expiring while earlier labels are printing. Completion reports are retried up to three times; the server treats repeated completion reports for the same station/job as successful, avoiding unnecessary reprints when the first response was lost.

The agent writes timestamped logs to `tools/niimbot/logs/niimbot-agent.log`. Log rotation is enabled at 5 MB with five backups, so scheduled-task output is retained even when the agent runs through `pythonw.exe`. A station-local process lock prevents a second agent instance from claiming jobs while the scheduled instance is already running.

If a processing lease expires, the server marks that job failed with an explicit "physical print outcome is unknown" message. It does not automatically requeue expired physical print jobs, because the label may already have printed before the connection was lost. Verify the printer output before manually requesting another label.

For GoDaddy/cPanel, an optional cron job can reconcile expired leases even while a station is offline. The CLI-only script is `tools/niimbot/reconcile-expired-jobs.php`. Test it first with `php tools/niimbot/reconcile-expired-jobs.php --dry-run`; configure the cPanel cron to run `php /absolute/path/to/AssetSys/tools/niimbot/reconcile-expired-jobs.php --apply` every five minutes. Use the actual PHP CLI executable and absolute project path shown by the hosting account. This task only marks expired jobs failed for manual review; it never triggers a physical reprint.

The agent logs per-label image-rendering time, USB print-command time, total label time, and batch elapsed time. Use these measurements to establish a baseline before changing print density or image transfer behavior. A successful printer command is not proof that the physical label is fully printed or scannable.

The current local agent expects the Python executable at:

`tools/niimbot/.venv311/Scripts/python.exe`

The local agent reads the generated PNG and passes it to the patched B1 V2 `PrinterClient` directly.

If Windows assigns a different COM port, update `PRINTER_PORT` in `tools/niimbot/agent.py` on that station and test the connection before printing production labels.

## Production multi-device registry

Production supports multiple independently authenticated NIIMBOT stations through the `niimbot_devices` table.

- Every station has a unique `device_id` and its own random 64-character hexadecimal agent token.
- Store only the token's SHA-256 hash in `niimbot_devices.token_hash`. Keep the raw token only in that station's ignored `tools/niimbot/agent-config.json`.
- The existing Property Custodian station is automatically enrolled on its first successful authenticated poll after the registry migration, using its existing `.env` credentials. Do not change its existing agent configuration.
- QR printing is restricted to Property Custodian accounts; their requests route to `property-custodian-b1`.
- Asset Manager accounts are view-only for QR labels and cannot queue single or bulk print jobs.
- Agents can claim up to five jobs per API request, but each label remains independently tracked and reported as completed or failed.
- A station can claim only jobs whose `target_device_id` matches its authenticated device ID. Row locking prevents duplicate claims.
- The `device_id` on a print job records which station actually claimed it.
- Set `is_enabled = 0` to revoke a station without affecting the other devices.

Run `database/migrations/2026_11_21_niimbot_devices.sql` before enabling a second production station. Register additional devices with an SQL INSERT containing the locally generated token hash. Never put raw agent tokens in SQL history, source control, chat, or logs.

## Testing

1. Connect the printer and verify the assigned COM port in Windows Device Manager.
2. Confirm that the Python environment can import and run `niimprint`.
3. Generate a QR label from the AssetSys QR page.
4. Confirm that the printer produces the complete label.
5. Scan the printed QR code and verify that the authenticated AssetSys scan page opens the correct asset.

A successful command alone does not guarantee a readable label. Verify the physical print and scan the QR code.

## Troubleshooting

- **Print completion timeout or unsupported `END_PRINT`:** The agent marks the job failed for manual review rather than treating the command as completed. Check the physical label before requesting another print. Do not automatically retry an uncertain job.
- **Blank label:** Verify that the B1-specific patch is applied, that the image rows are sent, and that the printer accepts `END_PAGE_PRINT` and the subsequent `END_PRINT` acknowledgement.
- **Connection failure:** Verify the USB connection and COM port.
- **Python executable missing:** Recreate the Python 3.11 virtual environment and reinstall the driver requirements.
- **QR does not scan:** Check print quality, label dimensions, and QR contrast.

## Security

QR tokens are identifiers, not authorization grants. AssetSys must authenticate the user and check their asset access permissions before displaying asset details. Do not put credentials or other secrets in QR codes.


## Automatic printer assignment and batch printing

Run `database/migrations/2026_11_26_niimbot_batch_print_routing.sql` after deploying the matching application code. It adds `target_device_id` and backfills existing queued jobs based on the requester's current role.

The assignment is server-side and cannot be overridden by browser input:
- Property Custodian → Property Custodian B1.
- Asset Manager → view-only; no QR print station is assigned.

Update `agent.py` on the Property Custodian station after pulling the feature branch. The Property Custodian uses a standalone copy under their Windows profile, so update that copy separately. Restart its scheduled task after installing the updated agent. The Asset Manager workstation does not need a NIIMBOT agent for QR printing.

The new agent requires the existing local `niimprintx` checkout at tested revision `be39f68` with `patches/b1-v2-printing.patch` applied. The persistent connection path calls the patched `PrinterClient` directly. Do not remove or replace that patch when updating the local driver.
