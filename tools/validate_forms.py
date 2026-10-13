"""Independent pypdf inspection of native AcroForm and both permitted FDF layouts."""

from __future__ import annotations

import io
import re
import sys
from pathlib import Path

from pypdf import PdfReader
from pypdf.generic import read_object
from pypdf._utils import read_non_whitespace

from validate_variable_text_appearances import verify_variable_text_appearance


def object_without_xref(path: Path):
    """Parse actual FDF object syntax without inventing a PDF header or page tree."""
    source = io.BytesIO(path.read_bytes())
    assert source.readline() == b"%FDF-1.2\n"
    assert source.readline().startswith(b"%")
    objects = {}
    class Resolver:
        strict = True

        def get_object(self, reference):
            assert reference.generation == 0
            return objects[reference.idnum]

    resolver = Resolver()
    while True:
        token = read_non_whitespace(source)
        source.seek(-1, 1)
        if token == b"t":
            assert source.read(7) == b"trailer"
            read_non_whitespace(source)
            source.seek(-1, 1)
            trailer = read_object(source, resolver)
            assert source.read().strip() == b"%%EOF"
            return objects[trailer.raw_get("/Root").idnum]
        header = source.readline().strip()
        match = re.fullmatch(rb"([1-9][0-9]*) 0 obj", header)
        assert match, header
        number = int(match[1])
        assert number not in objects
        objects[number] = read_object(source, resolver)
        read_non_whitespace(source)
        source.seek(-1, 1)
        assert source.read(6) == b"endobj"


def field_values(data):
    roots = data["/Fields"]
    assert len(roots) == 1
    root = roots[0].get_object()
    assert root["/T"] == "Person"
    values = {field["/T"]: field["/V"] for field in root["/Kids"]}
    assert values == {
        "Name": "Ada Lovelace",
        "Countries": ["FR", "BE"],
        "Consent": "/Yes",
        "Delivery": "/Email",
    }, values
    return values


directory = Path(sys.argv[1])
reader = PdfReader(directory / "acroform-fields.pdf")
catalog = reader.trailer["/Root"]
form = catalog["/AcroForm"]
assert form["/NeedAppearances"].value is False
assert "/Helv 12 Tf" in form["/DA"]
assert "/Helv" in form["/DR"]["/Font"]
assert catalog["/Extensions"]["/ADBE"]["/ExtensionLevel"] == 3
fields = reader.get_fields()
assert set(fields) == {
    "Person", "Person.Name", "Person.Countries", "Person.Consent",
    "Person.Delivery", "Person.Reset", "Person.Signature",
}
assert fields["Person.Name"]["/V"] == "Ada Lovelace"
assert fields["Person.Countries"]["/V"] == ["FR", "BE"]
assert fields["Person.Consent"]["/V"] == "/Yes"
assert fields["Person.Delivery"]["/V"] == "/Email"
assert "/V" not in fields["Person.Reset"]
assert "/V" not in fields["Person.Signature"]
widgets = reader.pages[0]["/Annots"]
assert len(widgets) == 7
terminal_fields = {str(reference.get_object()["/Parent"]["/T"]): reference.get_object()["/Parent"] for reference in widgets}
country_field = terminal_fields["Countries"]
assert country_field["/FT"] == "/Ch"
assert country_field["/Ff"] & (1 << 21)  # MultiSelect is bit 22.
assert not country_field["/Ff"] & (1 << 17)  # It is a list box, not Combo.
assert country_field["/Opt"] == [["FR", "France"], ["BE", "Belgique"]]
assert country_field["/V"] == ["FR", "BE"]
assert country_field["/I"] == [0, 1]
assert country_field.get("/TI", 0) == 0
assert [str(country_field["/Opt"][index][0]) for index in country_field["/I"]] == list(country_field["/V"])
selected_display = [str(country_field["/Opt"][index][1]) for index in country_field["/I"]]
assert selected_display == ["France", "Belgique"]
variable_appearance_proofs = {}
for reference in widgets:
    widget = reference.get_object()
    field = widget["/Parent"]
    name = str(field["/T"])
    if name == "Name":
        variable_appearance_proofs[name] = verify_variable_text_appearance(
            widget["/AP"]["/N"], reader, [str(field["/V"])],
            [["Grace Hopper"], ["Katherine Johnson"]], "Person.Name",
        )
    elif name == "Countries":
        variable_appearance_proofs[name] = verify_variable_text_appearance(
            widget["/AP"]["/N"], reader, selected_display,
            [["Belgique"], ["France"]], "Person.Countries",
            expected_selection_fills=2,
        )
assert set(variable_appearance_proofs) == {"Name", "Countries"}
page_identity = reader.pages[0].indirect_reference
for reference in widgets:
    widget = reference.get_object()
    assert widget["/Subtype"] == "/Widget"
    assert widget.raw_get("/P") == page_identity
    parent = widget.raw_get("/Parent")
    assert reference in parent.get_object()["/Kids"]
    normal = widget["/AP"]["/N"]
    if "/AS" in widget:
        assert widget["/AS"] in normal
        assert "/Off" in normal
        for state in normal.values():
            appearance = state.get_object()
            assert appearance["/Subtype"] == "/Form"
            assert appearance.get_data()
    else:
        assert normal["/Subtype"] == "/Form"
        assert normal.get_data()
signature = widgets[-1].get_object()["/Parent"]["/Lock"]
assert signature["/Type"] == "/SigFieldLock"
assert signature["/Action"] == "/All"
assert signature["/P"] == 2

minimal = object_without_xref(directory / "acroform-minimal.fdf")
assert "/Pages" not in minimal
minimal_values = field_values(minimal["/FDF"])
# PdfReader emits its expected warning for %FDF; it still parses the genuine
# classic xref and root data correctly. The minimal file uses its object parser.
traditional = PdfReader(directory / "acroform-traditional.fdf")
assert "/Pages" not in traditional.trailer["/Root"]
assert minimal_values == field_values(traditional.trailer["/Root"]["/FDF"])
print("AcroForm: six terminal fields, seven widgets, states/resources/locks validated; native minimal and traditional FDF agree. Editable Name/Countries AP parsed independently: complete Tx text, balanced q/Q and clipping, replaceable selection fills, outside frames, two actual France/Belgique lines, repeated source-prescribed replacement, nested marked content and no-Tx append regressions verified.")
