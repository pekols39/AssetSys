from PIL import Image, ImageDraw, ImageFont
from io import BytesIO
import base64
import os
import textwrap


WIDTH = 384
HEIGHT = 240
BACKGROUND = "white"
FOREGROUND = "black"


def load_font(size, bold=False):
    candidates = [
        "C:/Windows/Fonts/arialbd.ttf" if bold else "C:/Windows/Fonts/arial.ttf",
        "C:/Windows/Fonts/calibrib.ttf" if bold else "C:/Windows/Fonts/calibri.ttf",
    ]

    for path in candidates:
        if os.path.exists(path):
            return ImageFont.truetype(path, size)

    return ImageFont.load_default()


def wrap_text(draw, value, font, max_width):
    words = str(value or "-").split()
    if not words:
        return ["-"]

    lines = []
    current = ""

    for word in words:
        candidate = f"{current} {word}".strip()

        if draw.textbbox((0, 0), candidate, font=font)[2] <= max_width:
            current = candidate
            continue

        if current:
            lines.append(current)

        current = ""
        for character in word:
            candidate = current + character
            if draw.textbbox((0, 0), candidate, font=font)[2] <= max_width:
                current = candidate
            else:
                if current:
                    lines.append(current)
                current = character

    if current:
        lines.append(current)

    return lines


def draw_field(draw, x, y, label, value, max_width, label_font, value_font,
               max_lines=2):
    draw.text((x, y), label, fill=FOREGROUND, font=label_font)
    y += 15

    lines = wrap_text(draw, value, value_font, max_width)
    lines = lines[:max_lines]

    for line in lines:
        draw.text((x, y), line, fill=FOREGROUND, font=value_font)
        y += 15

    return y + 4


def render_label(job, output_path):
    image = Image.new("RGB", (WIDTH, HEIGHT), BACKGROUND)
    draw = ImageDraw.Draw(image)

    qr_base64 = job.get("qr_image_base64")
    if not qr_base64:
        raise ValueError("The print job has no QR image.")

    qr_bytes = base64.b64decode(qr_base64, validate=True)

    with Image.open(BytesIO(qr_bytes)) as qr_source:
        qr = qr_source.convert("RGB")

    qr_size = 176
    qr.thumbnail((qr_size, qr_size), Image.Resampling.LANCZOS)

    qr_x = 8 + (qr_size - qr.width) // 2
    qr_y = 32 + (qr_size - qr.height) // 2
    image.paste(qr, (qr_x, qr_y))

    draw.rectangle((5, 29, 187, 211), outline=FOREGROUND, width=1)
    draw.line((194, 8, 194, 232), fill=FOREGROUND, width=1)

    x = 203
    max_width = 172

    title_font = load_font(17, bold=True)
    label_font = load_font(11, bold=True)
    value_font = load_font(13)

    asset_name = str(job.get("asset_name") or "Unnamed Asset")
    title_lines = wrap_text(draw, asset_name, title_font, max_width)[:2]

    y = 8
    for line in title_lines:
        draw.text((x, y), line, fill=FOREGROUND, font=title_font)
        y += 20

    y += 3

    y = draw_field(
        draw, x, y, "ASSET CODE",
        job.get("asset_code"), max_width,
        label_font, value_font, max_lines=2
    )

    y = draw_field(
        draw, x, y, "ASSET TAG",
        job.get("finance_ffe_number") or "-",
        max_width, label_font, value_font, max_lines=4
    )

    y = draw_field(
        draw, x, y, "OWNER / DEPARTMENT",
        job.get("finance_owner_department") or "-",
        max_width, label_font, value_font, max_lines=2
    )

    image.save(output_path, format="PNG", optimize=True)
    return image


if __name__ == "__main__":
    print("NIIMBOT label renderer is ready.")
