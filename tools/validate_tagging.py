from pathlib import Path
import sys
from pypdf import PdfReader
from pypdf.generic import ArrayObject, DictionaryObject, IndirectObject
pdf = Path(sys.argv[1])
if pdf.is_dir():
    pdf = pdf / 'tagged-structure.pdf'
r = PdfReader(pdf, strict=True)
assert len(r.pages) == 3
catalog = r.trailer['/Root']
assert catalog['/MarkInfo']['/Marked'].value is True
assert catalog['/Lang'] == 'fr-FR'
root = catalog['/StructTreeRoot']
assert len(root['/K']) == 1
parent_nums = root['/ParentTree']['/Nums']
parents = {int(parent_nums[i]): parent_nums[i + 1].get_object() for i in range(0,len(parent_nums),2)}
assert set(parents) == {0,1,2,3}
assert root['/ParentTreeNextKey'] == 4
id_names = root['/IDTree']['/Names']
ids = {str(id_names[i]): id_names[i+1].get_object() for i in range(0,len(id_names),2)}
assert set(ids) == {'heading','paragraph','components','component-header','documentation-link'}
assert ids['heading']['/S'] == '/H1'
assert ids['paragraph']['/S'] == '/P'
assert ids['paragraph']['/K'][0] == 1
assert ids['paragraph']['/K'][1]['/Type'] == '/MCR'
assert ids['paragraph']['/K'][1]['/MCID'] == 0
assert ids['paragraph']['/K'][1]['/Pg'].indirect_reference == r.pages[1].indirect_reference
assert parents[0][1].get_object().indirect_reference == ids['paragraph'].indirect_reference
assert parents[1][0].get_object().indirect_reference == ids['paragraph'].indirect_reference
assert ids['component-header']['/A']['/Scope'] == '/Column'
assert ids['components']['/K'][1].get_object()['/K']['/A']['/Headers'][0] == 'component-header'
link = ids['documentation-link']
assert link['/S'] == '/Link'
assert link['/K'][0] == 0
assert link['/K'][1]['/Type'] == '/OBJR'
assert link['/K'][1]['/Obj'].indirect_reference == r.pages[2]['/Annots'][0]
assert r.pages[2]['/Annots'][0].get_object()['/StructParent'] == 3
assert parents[3].indirect_reference == link.indirect_reference
assert r.pages[0]['/StructParents'] == 0
assert r.pages[1]['/StructParents'] == 1
assert r.pages[2]['/StructParents'] == 2
assert len(parents[0]) == 2 and len(parents[1]) == 3 and len(parents[2]) == 1
for page in r.pages:
    text = page.extract_text()
    assert text
    operations = page.get_contents().operations
    mcids = [operands[1]['/MCID'] for operands, op in operations if op == b'BDC']
    assert len(mcids) == len(set(mcids))
    assert any(operands[0] == '/Artifact' for operands, op in operations if op == b'BMC')
assert 'premiere page' in r.pages[0].extract_text()
assert 'deuxieme page' in r.pages[1].extract_text()
assert 'documentation' in r.pages[2].extract_text()
print('Independent pypdf validation: 3 pages; one paragraph across pages; IDTree headers; marked content, artifacts and OBJR annotation parent associations verified.')

