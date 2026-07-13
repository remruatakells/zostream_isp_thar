#!/usr/bin/env python3
"""Render the full MikroTik setup Markdown guide as a styled PDF."""

from __future__ import annotations

import html
import re
import sys
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    NextPageTemplate,
    PageBreak,
    PageTemplate,
    Paragraph,
    Preformatted,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.platypus.tableofcontents import TableOfContents


ROOT = Path(__file__).resolve().parent
SOURCE = ROOT / "MIKROTIK_VPS_LARAVEL_FULL_SETUP.md"
OUTPUT = ROOT / "MIKROTIK_VPS_LARAVEL_FULL_SETUP.pdf"

NAVY = colors.HexColor("#102A43")
BLUE = colors.HexColor("#1677A8")
CYAN = colors.HexColor("#E8F4F8")
PALE = colors.HexColor("#F5F8FA")
INK = colors.HexColor("#243B53")
MUTED = colors.HexColor("#627D98")
GREEN = colors.HexColor("#1F7A5A")
ORANGE = colors.HexColor("#D97706")
LINE = colors.HexColor("#CBD5E1")


def register_fonts() -> None:
    font_dir = Path("/System/Library/Fonts/Supplemental")
    pdfmetrics.registerFont(TTFont("Guide", str(font_dir / "Arial.ttf")))
    pdfmetrics.registerFont(TTFont("Guide-Bold", str(font_dir / "Arial Bold.ttf")))
    pdfmetrics.registerFont(TTFont("Guide-Italic", str(font_dir / "Arial Italic.ttf")))
    pdfmetrics.registerFont(TTFont("Guide-BoldItalic", str(font_dir / "Arial Bold Italic.ttf")))
    pdfmetrics.registerFont(TTFont("Code", str(font_dir / "Courier New.ttf")))
    pdfmetrics.registerFont(TTFont("Code-Bold", str(font_dir / "Courier New Bold.ttf")))
    pdfmetrics.registerFontFamily(
        "Guide",
        normal="Guide",
        bold="Guide-Bold",
        italic="Guide-Italic",
        boldItalic="Guide-BoldItalic",
    )


def inline_markup(value: str) -> str:
    tokens: list[str] = []

    def hold_code(match: re.Match[str]) -> str:
        tokens.append(f'<font name="Code" color="#0F4C5C">{html.escape(match.group(1))}</font>')
        return f"@@CODE{len(tokens) - 1}@@"

    value = re.sub(r"`([^`]+)`", hold_code, value)
    value = html.escape(value)
    value = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", value)
    for index, token in enumerate(tokens):
        value = value.replace(f"@@CODE{index}@@", token)
    return value


def styles():
    sheet = getSampleStyleSheet()
    common = dict(fontName="Guide", textColor=INK)
    return {
        "body": ParagraphStyle(
            "Body", parent=sheet["BodyText"], fontSize=9.5, leading=14,
            spaceAfter=6, **common
        ),
        "h1": ParagraphStyle(
            "H1", parent=sheet["Heading1"], fontName="Guide-Bold", fontSize=23,
            leading=29, textColor=NAVY, spaceAfter=10, alignment=TA_CENTER
        ),
        "h2": ParagraphStyle(
            "H2", parent=sheet["Heading2"], fontName="Guide-Bold", fontSize=15,
            leading=19, textColor=NAVY, spaceBefore=14, spaceAfter=7, keepWithNext=True
        ),
        "h3": ParagraphStyle(
            "H3", parent=sheet["Heading3"], fontName="Guide-Bold", fontSize=11.5,
            leading=15, textColor=BLUE, spaceBefore=10, spaceAfter=5, keepWithNext=True
        ),
        "bullet": ParagraphStyle(
            "Bullet", parent=sheet["BodyText"], fontName="Guide", fontSize=9.3,
            leading=13.5, leftIndent=15, firstLineIndent=0, bulletIndent=3,
            textColor=INK, spaceAfter=3
        ),
        "quote": ParagraphStyle(
            "Quote", parent=sheet["BodyText"], fontName="Guide", fontSize=9.2,
            leading=13.5, leftIndent=12, rightIndent=8, borderWidth=0,
            borderPadding=8, backColor=colors.HexColor("#FFF7E6"),
            textColor=colors.HexColor("#7C4A03"), spaceBefore=4, spaceAfter=8
        ),
        "code": ParagraphStyle(
            "Code", parent=sheet["Code"], fontName="Code", fontSize=7.2,
            leading=10, leftIndent=0, rightIndent=0, borderPadding=8,
            backColor=colors.HexColor("#EFF4F7"), textColor=colors.HexColor("#153E4A"),
            spaceBefore=4, spaceAfter=8
        ),
        "small": ParagraphStyle(
            "Small", parent=sheet["BodyText"], fontName="Guide", fontSize=7.7,
            leading=10, textColor=INK
        ),
        "table_header": ParagraphStyle(
            "TableHeader", parent=sheet["BodyText"], fontName="Guide-Bold",
            fontSize=8, leading=10, textColor=colors.white
        ),
        "cover_sub": ParagraphStyle(
            "CoverSub", parent=sheet["BodyText"], fontName="Guide", fontSize=13,
            leading=19, textColor=MUTED, alignment=TA_CENTER
        ),
        "cover_meta": ParagraphStyle(
            "CoverMeta", parent=sheet["BodyText"], fontName="Guide-Bold", fontSize=9.5,
            leading=14, textColor=BLUE, alignment=TA_CENTER
        ),
        "toc_title": ParagraphStyle(
            "TocTitle", parent=sheet["Heading1"], fontName="Guide-Bold", fontSize=22,
            leading=27, textColor=NAVY, spaceAfter=15
        ),
    }


class GuideDocTemplate(BaseDocTemplate):
    def __init__(self, filename: str, **kwargs):
        super().__init__(filename, **kwargs)
        page_w, page_h = A4
        frame = Frame(
            18 * mm, 18 * mm, page_w - 36 * mm, page_h - 34 * mm,
            id="normal", leftPadding=0, rightPadding=0, topPadding=8 * mm, bottomPadding=5 * mm
        )
        cover_frame = Frame(
            22 * mm, 22 * mm, page_w - 44 * mm, page_h - 44 * mm,
            id="cover", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0
        )
        self.addPageTemplates([
            PageTemplate(id="Cover", frames=[cover_frame], onPage=self.cover_page),
            PageTemplate(id="Body", frames=[frame], onPage=self.body_page),
        ])

    @staticmethod
    def cover_page(canvas, doc):
        width, height = A4
        canvas.saveState()
        canvas.setFillColor(colors.white)
        canvas.rect(0, 0, width, height, fill=1, stroke=0)
        canvas.setFillColor(NAVY)
        canvas.rect(0, height - 34 * mm, width, 34 * mm, fill=1, stroke=0)
        canvas.setFillColor(BLUE)
        canvas.rect(0, 0, width, 9 * mm, fill=1, stroke=0)
        canvas.restoreState()

    @staticmethod
    def body_page(canvas, doc):
        width, height = A4
        canvas.saveState()
        canvas.setFillColor(colors.white)
        canvas.rect(0, 0, width, height, fill=1, stroke=0)
        canvas.setStrokeColor(LINE)
        canvas.setLineWidth(0.5)
        canvas.line(18 * mm, height - 14 * mm, width - 18 * mm, height - 14 * mm)
        canvas.setFont("Guide-Bold", 7.5)
        canvas.setFillColor(MUTED)
        canvas.drawString(18 * mm, height - 10.5 * mm, "ZoStream ISP • MikroTik VPS Setup")
        canvas.setFont("Guide", 7.5)
        canvas.drawRightString(width - 18 * mm, 10 * mm, f"Page {doc.page}")
        canvas.setFillColor(BLUE)
        canvas.rect(18 * mm, 8 * mm, 20 * mm, 1.2, fill=1, stroke=0)
        canvas.restoreState()

    def afterFlowable(self, flowable):
        if isinstance(flowable, Paragraph) and hasattr(flowable, "toc_level"):
            text = flowable.getPlainText()
            key = f"section-{self.seq.nextf('section')}"
            self.canv.bookmarkPage(key)
            self.canv.addOutlineEntry(text, key, level=flowable.toc_level, closed=False)
            self.notify("TOCEntry", (flowable.toc_level, text, self.page, key))


def parse_table(lines: list[str], pos: int, st) -> tuple[Table, int]:
    rows: list[list[str]] = []
    while pos < len(lines) and lines[pos].strip().startswith("|"):
        cells = [cell.strip() for cell in lines[pos].strip().strip("|").split("|")]
        if not all(re.fullmatch(r":?-{3,}:?", cell or "") for cell in cells):
            rows.append(cells)
        pos += 1
    max_cols = max(len(row) for row in rows)
    rows = [row + [""] * (max_cols - len(row)) for row in rows]
    cooked = []
    for r_index, row in enumerate(rows):
        style = st["table_header"] if r_index == 0 else st["small"]
        cooked.append([Paragraph(inline_markup(cell), style) for cell in row])
    usable = A4[0] - 36 * mm
    col_widths = [usable / max_cols] * max_cols
    table = Table(cooked, colWidths=col_widths, repeatRows=1, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("BACKGROUND", (0, 1), (-1, -1), colors.white),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, PALE]),
        ("GRID", (0, 0), (-1, -1), 0.35, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    return table, pos


def markdown_story(text: str, st) -> list:
    lines = text.splitlines()
    story: list = []
    pos = 0
    first_heading = True
    while pos < len(lines):
        line = lines[pos].rstrip()
        stripped = line.strip()
        if not stripped:
            pos += 1
            continue

        if stripped.startswith("```"):
            language = stripped[3:].strip()
            pos += 1
            code_lines = []
            while pos < len(lines) and not lines[pos].strip().startswith("```"):
                code_lines.append(lines[pos].rstrip())
                pos += 1
            pos += 1
            label = f"{language.upper()}\n" if language else ""
            story.append(Preformatted(label + "\n".join(code_lines), st["code"], maxLineLength=112))
            continue

        if stripped.startswith("# ") and first_heading:
            first_heading = False
            title = stripped[2:].strip()
            story.extend([
                Spacer(1, 48 * mm),
                Paragraph("MIKROTIK • WIREGUARD • LARAVEL", st["cover_meta"]),
                Spacer(1, 9 * mm),
                Paragraph(inline_markup(title), st["h1"]),
                Spacer(1, 7 * mm),
                Paragraph(
                    "VPS-hosted ISP admin panel-a MikroTik router pakhat emaw, tam zawk emaw, "
                    "secure taka connect dan kimchang.", st["cover_sub"]
                ),
                Spacer(1, 18 * mm),
                Table(
                    [[Paragraph("TESTED", st["table_header"]), Paragraph("RB5009UG+S+ • RouterOS 7.19.6 • Ubuntu VPS", st["small"])],
                     [Paragraph("NETWORK", st["table_header"]), Paragraph("WireGuard 10.77.0.0/24 • VPS UDP 51820", st["small"])],
                     [Paragraph("UPDATED", st["table_header"]), Paragraph("13 July 2026", st["small"])],],
                    colWidths=[31 * mm, 104 * mm],
                    style=TableStyle([
                        ("BACKGROUND", (0, 0), (0, -1), NAVY),
                        ("BACKGROUND", (1, 0), (1, -1), PALE),
                        ("GRID", (0, 0), (-1, -1), 0.5, LINE),
                        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                        ("LEFTPADDING", (0, 0), (-1, -1), 8),
                        ("TOPPADDING", (0, 0), (-1, -1), 8),
                        ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
                    ])
                ),
                Spacer(1, 24 * mm),
                Paragraph("Security-first production guide", st["cover_meta"]),
                NextPageTemplate("Body"),
                PageBreak(),
            ])
            toc = TableOfContents()
            toc.levelStyles = [
                ParagraphStyle("TOC0", fontName="Guide", fontSize=9.2, leading=14,
                               leftIndent=0, firstLineIndent=0, textColor=INK, spaceAfter=2),
                ParagraphStyle("TOC1", fontName="Guide", fontSize=8.4, leading=12,
                               leftIndent=12, firstLineIndent=0, textColor=MUTED),
            ]
            story.extend([Paragraph("Contents", st["toc_title"]), toc, PageBreak()])
            pos += 1
            continue

        heading = re.match(r"^(#{2,3})\s+(.+)$", stripped)
        if heading:
            level = len(heading.group(1)) - 2
            paragraph = Paragraph(inline_markup(heading.group(2)), st["h2" if level == 0 else "h3"])
            paragraph.toc_level = level
            story.append(paragraph)
            pos += 1
            continue

        if stripped.startswith("|") and pos + 1 < len(lines) and lines[pos + 1].strip().startswith("|"):
            table, pos = parse_table(lines, pos, st)
            story.extend([table, Spacer(1, 6)])
            continue

        if stripped.startswith(">"):
            quote = stripped[1:].strip()
            pos += 1
            while pos < len(lines) and lines[pos].strip().startswith(">"):
                quote += " " + lines[pos].strip()[1:].strip()
                pos += 1
            story.append(Paragraph(inline_markup(quote), st["quote"]))
            continue

        bullet = re.match(r"^[-*]\s+(.+)$", stripped)
        ordered = re.match(r"^(\d+)\.\s+(.+)$", stripped)
        if bullet or ordered:
            if bullet:
                story.append(Paragraph(inline_markup(bullet.group(1)), st["bullet"], bulletText="•"))
            else:
                story.append(Paragraph(inline_markup(ordered.group(2)), st["bullet"], bulletText=ordered.group(1) + "."))
            pos += 1
            continue

        paragraph_lines = [stripped]
        pos += 1
        while pos < len(lines):
            nxt = lines[pos].strip()
            if (not nxt or nxt.startswith(("#", "```", ">", "|")) or
                    re.match(r"^[-*]\s+", nxt) or re.match(r"^\d+\.\s+", nxt)):
                break
            paragraph_lines.append(nxt)
            pos += 1
        story.append(Paragraph(inline_markup(" ".join(paragraph_lines)), st["body"]))

    return story


def main() -> int:
    register_fonts()
    st = styles()
    source = SOURCE.read_text(encoding="utf-8")
    doc = GuideDocTemplate(
        str(OUTPUT), pagesize=A4,
        leftMargin=18 * mm, rightMargin=18 * mm,
        topMargin=18 * mm, bottomMargin=18 * mm,
        title="ZoStream ISP MikroTik VPS Laravel Full Setup",
        author="ZoStream ISP",
        subject="MikroTik RouterOS 7, WireGuard and Laravel admin panel deployment guide",
    )
    doc.multiBuild(markdown_story(source, st))
    print(OUTPUT)
    return 0


if __name__ == "__main__":
    sys.exit(main())
