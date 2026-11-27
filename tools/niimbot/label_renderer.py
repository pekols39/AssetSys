from PIL import Image, ImageDraw, ImageFont
from io import BytesIO
import base64
import os


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


def fit_font(draw, value, max_width, start_size=14, min_size=8):
    text = str(value or "-")
    for size in range(start_size, min_size - 1, -1):
        font = load_font(size)
        if draw.textbbox((0, 0), text, font=font)[2] <= max_width:
            return font
    return load_font(min_size)


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
               max_lines=2, line_height=17, field_height=55):
    draw.text((x, y), label, fill=FOREGROUND, font=label_font)

    lines = wrap_text(draw, value, value_font, max_width)[:max_lines]
    value_y = y + 17

    for line in lines:
        draw.text((x, value_y), line, fill=FOREGROUND, font=value_font)
        value_y += line_height

    return y + field_height


def prepare_qr_image(qr_source, size):
    qr = qr_source.convert("RGB")

    # Remove only the excess white margin around the QR artwork, then restore
    # a small quiet zone so the code fills the left panel but remains scannable.
    grayscale = qr.convert("L")
    artwork = grayscale.point(lambda pixel: 255 if pixel < 245 else 0)
    bounds = artwork.getbbox()

    if bounds:
        qr = qr.crop(bounds)

    quiet_zone = 6
    square_size = max(qr.width, qr.height) + quiet_zone * 2
    square = Image.new("RGB", (square_size, square_size), "white")
    square_x = (square_size - qr.width) // 2
    square_y = (square_size - qr.height) // 2
    square.paste(qr, (square_x, square_y))

    return square.resize((size, size), Image.Resampling.NEAREST)


def render_label(job, output_path):
    image = Image.new("RGB", (WIDTH, HEIGHT), BACKGROUND)
    draw = ImageDraw.Draw(image)

    qr_base64 = job.get("qr_image_base64")
    if not qr_base64:
        raise ValueError("The print job has no QR image.")

    qr_bytes = base64.b64decode(qr_base64, validate=True)

    with Image.open(BytesIO(qr_bytes)) as qr_source:
        qr_size = 178
        qr = prepare_qr_image(qr_source, qr_size)

    # Center the enlarged QR within the existing left-side frame.
    qr_x = 7
    qr_y = 31
    image.paste(qr, (qr_x, qr_y))

    draw.rectangle((5, 29, 187, 211), outline=FOREGROUND, width=1)
    draw.line((194, 8, 194, 232), fill=FOREGROUND, width=1)

    x = 203
    max_width = 172

    label_font = load_font(12, bold=True)
    value_font = load_font(14)
    asset_name_font = load_font(15, bold=True)

    y = 8
    y = draw_field(
        draw, x, y, "ASSET NAME",
        job.get("asset_name") or "-",
        max_width, label_font, asset_name_font,
    )

    y = draw_field(
        draw, x, y, "ASSET CODE",
        job.get("asset_code") or "-",
        max_width, label_font, value_font,
    )

    asset_tag = job.get("finance_ffe_number") or "-"
    asset_tag_font = fit_font(draw, asset_tag, max_width, start_size=14, min_size=8)
    y = draw_field(
        draw, x, y, "ASSET TAG",
        asset_tag,
        max_width, label_font, asset_tag_font, max_lines=1,
    )

    draw_field(
        draw, x, y, "OWNER / DEPARTMENT",
        job.get("finance_owner_department") or "-",
        max_width, label_font, value_font,
    )

    image.save(output_path, format="PNG", optimize=True)
    return image


if __name__ == "__main__":
    print("NIIMBOT label renderer is ready.")
