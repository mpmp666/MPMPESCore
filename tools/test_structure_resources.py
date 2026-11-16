#!/usr/bin/env python3
"""Offline structural validation for converted gzip JSON structure resources."""
import base64
import gzip
import json
import struct
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
LIB=ROOT/'resources/structures'
manifest=json.loads((LIB/'manifest.json').read_text())
assert manifest['format']==1
assert len(manifest['templates'])==1212
assert sum(len(v) for v in manifest['families'].values())==1212
assert len(json.loads((LIB/'pools.json').read_text()))==188
loot=json.loads((LIB/'loot_tables.json').read_text())
assert len(loot)>=1300
state_ids=set()
blocks_total=tiles_total=connectors_total=markers_total=0
for key,meta in manifest['templates'].items():
    raw=gzip.decompress((LIB/meta['file']).read_bytes())
    t=json.loads(raw)
    assert t['format']==1 and t['size']==meta['size'],key
    packed=base64.b64decode(t['blocks'],validate=True)
    assert len(packed)%8==0,key
    seen=set()
    for x,y,z,p in struct.iter_unpack('<4H',packed):
        assert x<t['size'][0] and y<t['size'][1] and z<t['size'][2] and p<len(t['palette']),key
        assert (x,y,z) not in seen,(key,x,y,z)
        seen.add((x,y,z)); blocks_total+=1
    for rotation in t['palette']:
        assert len(rotation)==4
        for block_id,meta_id in rotation:
            assert 0<=block_id<=255 and 0<=meta_id<=15
            state_ids.add((block_id,meta_id))
    for tile in t['tiles']:
        x,y,z=tile['pos'];assert x<t['size'][0] and y<t['size'][1] and z<t['size'][2]
        tiles_total+=1
    for c in t['connectors']:
        x,y,z=c['pos'];assert x<t['size'][0] and y<t['size'][1] and z<t['size'][2]
        assert c['front'] in {'north','south','east','west','up','down'}
        connectors_total+=1
    for m in t['markers']:
        x,y,z=m['pos'];assert x<t['size'][0] and y<t['size'][1] and z<t['size'][2]
        markers_total+=1
print(json.dumps({'templates':len(manifest['templates']),'mapped_states':len(state_ids),'blocks':blocks_total,'tiles':tiles_total,'connectors':connectors_total,'markers':markers_total,'loot_tables':len(loot.get('loot_tables',loot)),'status':'ok'},ensure_ascii=False))
