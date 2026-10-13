"""Independent regression for the editable appearances supplied by our examples.

ISO 32000-1:2008, 12.7.3.3, physical page 444 replaces /Tx BMC through its
matching EMC. Without that section, a conforming reader appends new contents.
That fallback is valid PDF behavior; these examples deliberately supply a
replaceable section so their original values do not survive an edit.

This oracle parses emitted PDF operators and simulates that source-prescribed
replacement. It neither invokes Acrobat nor claims to implement a form filler,
font layout engine, or a general validator of every permitted appearance.
"""

from __future__ import annotations

import hashlib

from pypdf.generic import (
    ByteStringObject, ContentStream, DecodedStreamObject, FloatObject, NameObject,
    NumberObject, TextStringObject,
)


TEXT_OPERATORS = {
    b"BT", b"ET", b"Tc", b"Tw", b"Tz", b"TL", b"Tf", b"Tr", b"Ts",
    b"Td", b"TD", b"Tm", b"T*", b"Tj", b"TJ", b"'", b'"',
}
SHOW_OPERATORS = {b"Tj", b"TJ", b"'", b'"'}
CLIP_OPERATORS = {b"W", b"W*", b"n"}
SELECTION_FILL_OPERATORS = {b"f", b"F", b"f*"}


def operations_bytes(operations, reader):
    """Canonical operator bytes, using pypdf's real PDF operand serializer."""
    stream = ContentStream(None, reader)
    stream.operations = list(operations)
    return stream.get_data()


def reparsed(operations, reader):
    stream = DecodedStreamObject()
    stream.set_data(operations_bytes(operations, reader))
    return list(ContentStream(stream, reader).operations)


def tx_ranges(operations):
    """Find matching EMC with a stack, including nested non-Tx marked content."""
    stack = []
    ranges = []
    for index, (operands, operator) in enumerate(operations):
        if operator in (b"BMC", b"BDC"):
            assert operands and isinstance(operands[0], NameObject)
            stack.append((index, str(operands[0]), operator))
        elif operator == b"EMC":
            assert stack, "Unmatched EMC in the example appearance."
            start, tag, opener = stack.pop()
            if tag == "/Tx" and opener == b"BMC":
                ranges.append((start, index))
    assert not stack, "Unclosed marked content in the example appearance."
    return sorted(ranges)


def single_tx_range(operations, label):
    ranges = tx_ranges(operations)
    assert len(ranges) == 1, f"{label}: the editable example needs one replaceable /Tx BMC section; got {ranges}."
    return ranges[0]


def shown_string(operands, operator):
    if operator == b"TJ":
        values = [item for item in operands[0] if isinstance(item, (TextStringObject, ByteStringObject))]
    else:
        values = [operands[-1]]
    assert all(isinstance(item, (TextStringObject, ByteStringObject)) for item in values)
    return "".join(str(item) if isinstance(item, TextStringObject) else bytes(item).decode("latin-1") for item in values)


def shown_lines(operations):
    """Observe the explicit, unrotated text baselines used by these examples."""
    matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]
    leading = 0.0
    lines = []

    def move(tx, ty):
        matrix[4] += float(tx) * matrix[0] + float(ty) * matrix[2]
        matrix[5] += float(tx) * matrix[1] + float(ty) * matrix[3]

    for operands, operator in operations:
        if operator == b"BT":
            matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]
        elif operator == b"Tm":
            assert len(operands) == 6
            matrix = [float(item) for item in operands]
        elif operator in (b"Td", b"TD"):
            move(*operands)
            if operator == b"TD":
                leading = -float(operands[1])
        elif operator == b"TL":
            leading = float(operands[0])
        elif operator == b"T*":
            move(0, -leading)
        if operator in (b"'", b'"'):
            move(0, -leading)
        if operator in SHOW_OPERATORS:
            # These examples use unrotated, horizontal text. Glyph advances
            # affect x only; their y coordinate is the explicit line baseline.
            assert matrix[1:3] == [0.0, 0.0]
            lines.append((shown_string(operands, operator), matrix[5]))
    return lines


def assert_editable_example(operations, expected_lines, label, expected_selection_fills=None):
    start, end = single_tx_range(operations, label)
    for index, (_, operator) in enumerate(operations):
        if operator in TEXT_OPERATORS:
            assert start < index < end, f"{label}: {operator!r} at {index} would survive replacement outside /Tx."
        # This is the declared contract of these three examples, not a general
        # prohibition of outside q/Q, paths, fills or clipping in PDF forms.
        if operator in {b"q", b"Q"} | CLIP_OPERATORS | SELECTION_FILL_OPERATORS:
            assert start < index < end, f"{label}: replaceable graphics state, clipping or selection fill would survive outside /Tx."
        if operator in (b"S", b"s"):
            assert not start < index < end, f"{label}: the explicit frame stroke must survive outside /Tx."
    depth = 0
    saves = clips = fills = 0
    for _, operator in operations[start + 1:end]:
        if operator == b"q":
            depth += 1
            saves += 1
        elif operator == b"Q":
            depth -= 1
            assert depth >= 0, f"{label}: Q has no matching q inside /Tx."
        elif operator in CLIP_OPERATORS:
            assert depth > 0, f"{label}: clipping must be isolated by q/Q inside /Tx."
            clips += operator in (b"W", b"W*")
        elif operator in SELECTION_FILL_OPERATORS:
            fills += 1
    assert depth == 0 and saves > 0, f"{label}: /Tx must contain balanced q/Q."
    assert clips > 0, f"{label}: missing replaceable example clipping."
    if expected_selection_fills is not None:
        assert fills == expected_selection_fills, (label, fills, expected_selection_fills)
    outside = operations[:start] + operations[end + 1:]
    assert any(operator == b"re" for _, operator in outside), f"{label}: missing outside frame rectangle."
    assert any(operator in (b"S", b"s") for _, operator in outside), f"{label}: missing outside frame stroke."
    lines = shown_lines(operations)
    assert [text for text, _ in lines] == list(expected_lines), (label, lines, expected_lines)
    assert len({baseline for _, baseline in lines}) == len(expected_lines), f"{label}: displayed options must occupy distinct lines."
    return outside


def replacement_contents(operations, new_lines, label):
    start, end = single_tx_range(operations, label)
    font = next((operands for operands, operator in operations[start + 1:end] if operator == b"Tf"), None)
    assert font is not None and len(font) == 2, f"{label}: missing existing text font."
    result = [([NameObject("/Tx")], b"BMC"), ([], b"q"), ([], b"BT"), (list(font), b"Tf")]
    clip_index = next(index for index in range(start + 1, end) if operations[index][1] in (b"W", b"W*"))
    assert operations[clip_index - 1][1] == b"re" and operations[clip_index + 1][1] == b"n"
    # Keep the supplied rectangular clipping bounds in the replacement. The
    # old clipping operators are replaced with the rest of the old Tx block.
    result[2:2] = operations[clip_index - 1:clip_index + 2]
    # Controlled coordinates and ASCII samples isolate section replacement
    # from viewer-specific layout and font encoding. The font already exists
    # in this appearance; no new resource or DR/Resources conflict is invented.
    first_baseline = shown_lines(operations)[0][1]
    result.append(([NumberObject(8), FloatObject(first_baseline)], b"Td"))
    for index, value in enumerate(new_lines):
        assert value.isascii()
        if index:
            result.append(([NumberObject(0), NumberObject(-16)], b"Td"))
        result.append(([TextStringObject(value)], b"Tj"))
    result.extend([([], b"ET"), ([], b"Q"), ([], b"EMC")])
    return result


def source_update(operations, new_contents):
    """The page-444 replacement or permitted no-Tx append fallback."""
    ranges = tx_ranges(operations)
    if not ranges:
        return list(operations) + list(new_contents), "append"
    assert len(ranges) == 1, "The controlled simulation uses one Tx section."
    start, end = ranges[0]
    return list(operations[:start]) + list(new_contents) + list(operations[end + 1:]), "replace"


def assert_rejected(operations, expected_lines, label):
    try:
        assert_editable_example(operations, expected_lines, label)
    except AssertionError:
        return
    raise AssertionError(f"{label}: the regression unexpectedly accepted its deliberately broken editable appearance.")


def verify_variable_text_appearance(appearance, reader, initial_lines, replacement_sequences, label, expected_selection_fills=0):
    """Inspect actual AP and exercise repeat replacement plus negative mutations."""
    operations = list(ContentStream(appearance, reader).operations)
    outside = assert_editable_example(operations, initial_lines, label, expected_selection_fills)
    outside_bytes = operations_bytes(outside, reader)
    initial_bytes = appearance.get_data()
    assert len(replacement_sequences) >= 2
    current = operations
    for index, new_lines in enumerate(replacement_sequences):
        updated, mode = source_update(current, replacement_contents(current, new_lines, label))
        assert mode == "replace"
        current = reparsed(updated, reader)
        actual_outside = assert_editable_example(current, new_lines, f"{label}, update {index + 1}")
        # Equality of all shown strings excludes both old and intermediate
        # values, including when an option is intentionally selected again.
        assert operations_bytes(actual_outside, reader) == outside_bytes
    assert appearance.get_data() == initial_bytes, "The oracle must not mutate the original emitted AP."

    start, end = single_tx_range(operations, label)
    without_tx = operations[:start] + operations[start + 1:end] + operations[end + 1:]
    assert tx_ranges(without_tx) == []
    new_lines = replacement_sequences[0]
    appended, mode = source_update(without_tx, replacement_contents(operations, new_lines, label))
    assert mode == "append"
    assert [text for text, _ in shown_lines(reparsed(appended, reader))] == list(initial_lines) + list(new_lines)
    assert_rejected(without_tx, initial_lines, label + ", original no-Tx regression")
    assert_rejected(reparsed(appended, reader), new_lines, label + ", stale values after append")

    # A text object outside Tx remains syntactically a text object, yet survives
    # replacement. This mutation prevents accepting a mere decorative Tx tag.
    text_start = next(index for index in range(start + 1, end) if operations[index][1] == b"BT")
    text_end = next(index for index in range(text_start + 1, end) if operations[index][1] == b"ET")
    moved = operations[:text_start] + operations[text_end + 1:] + operations[text_start:text_end + 1]
    assert_rejected(reparsed(moved, reader), initial_lines, label + ", text outside Tx")

    outer_state = operations[:start] + [([], b"q")] + operations[start:end + 1] + [([], b"Q")] + operations[end + 1:]
    assert_rejected(reparsed(outer_state, reader), initial_lines, label + ", q/Q outside Tx")
    clip_index = next(index for index in range(start + 1, end) if operations[index][1] in (b"W", b"W*"))
    assert operations[clip_index - 1][1] == b"re" and operations[clip_index + 1][1] == b"n"
    outside_clip = operations[:clip_index - 1] + operations[clip_index + 2:] + operations[clip_index - 1:clip_index + 2]
    assert_rejected(reparsed(outside_clip, reader), initial_lines, label + ", clipping outside Tx")
    negative_cases = 5
    if expected_selection_fills:
        fill_index = next(index for index in range(start + 1, end) if operations[index][1] in SELECTION_FILL_OPERATORS)
        assert operations[fill_index - 1][1] == b"re"
        outside_fill = operations[:fill_index - 1] + operations[fill_index + 1:] + operations[fill_index - 1:fill_index + 1]
        assert_rejected(reparsed(outside_fill, reader), initial_lines, label + ", selection fill outside Tx")
        negative_cases += 1

    # Nested marked content needs its matching EMC, not the first EMC in the
    # stream. Replace the entire outer Tx and preserve its outside frame.
    nested = operations[:start + 1] + [([NameObject("/Span")], b"BMC")] + operations[start + 1:end] + [([], b"EMC")] + operations[end:]
    nested_updated, mode = source_update(nested, replacement_contents(nested, new_lines, label))
    assert mode == "replace"
    nested_outside = assert_editable_example(reparsed(nested_updated, reader), new_lines, label + ", nested marked content")
    assert operations_bytes(nested_outside, reader) == outside_bytes
    return {
        "initial_lines": list(initial_lines), "successive_replacements": len(replacement_sequences),
        "outside_operations_sha256": hashlib.sha256(outside_bytes).hexdigest(),
        "negative_cases": negative_cases, "nested_marked_content": True, "no_tx_fallback": "append",
        "scoped_clipping_and_graphics_state": True, "initial_selection_fills": expected_selection_fills,
    }
