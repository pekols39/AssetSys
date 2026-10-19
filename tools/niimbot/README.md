# NIIMBOT B1 Direct Printing — AssetSys

## Purpose

AssetSys uses a local Python `niimprint` driver to send QR inventory labels directly to a NIIMBOT B1 printer over USB. PHP invokes the Python executable and passes the generated label image to the driver.

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

The patch adapts the print sequence for the tested B1 V2 setup. It sends a B1 wake-up packet, uses the required start-print payload and dimensions, sends the image rows, ends the page, and waits two seconds. It intentionally does not call the upstream `END_PRINT` handshake.

Review the upstream repository's license and instructions before installing or redistributing its source.

## AssetSys configuration

The current print endpoint expects the Python executable at:

`tools/niimbot/.venv311/Scripts/python.exe`

It invokes `niimprint` with the B1 model, USB transport, configured COM port, density setting, and generated PNG image.

If Windows assigns a different COM port, update the port configuration in `public/assets/print-qr-label.php` and test the connection before printing production labels.

## Testing

1. Connect the printer and verify the assigned COM port in Windows Device Manager.
2. Confirm that the Python environment can import and run `niimprint`.
3. Generate a QR label from the AssetSys QR page.
4. Confirm that the printer produces the complete label.
5. Scan the printed QR code and verify that the authenticated AssetSys scan page opens the correct asset.

A successful command alone does not guarantee a readable label. Verify the physical print and scan the QR code.

## Troubleshooting

- **Blank label:** Verify that the B1-specific patch is applied and that the print sequence ends with `END_PAGE_PRINT` followed by the two-second wait.
- **Connection failure:** Verify the USB connection and COM port.
- **Python executable missing:** Recreate the Python 3.11 virtual environment and reinstall the driver requirements.
- **QR does not scan:** Check print quality, label dimensions, and QR contrast.

## Security

QR tokens are identifiers, not authorization grants. AssetSys must authenticate the user and check their asset access permissions before displaying asset details. Do not put credentials or other secrets in QR codes.
