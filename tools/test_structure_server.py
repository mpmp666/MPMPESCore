#!/usr/bin/env python3
"""Run isolated PHP server integration, preserving the temp workspace for inspection."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php',default=str(ROOT/'bin/php7/bin/php'))
parser.add_argument('--port',type=int,default=19142)
args=parser.parse_args()
if not 1024<=args.port<=65535:
    parser.error('port must be between 1024 and 65535')
workspace=Path(tempfile.mkdtemp(prefix='mpmpescore-structures-'))
shutil.copytree(ROOT/'tools/fixtures/StructureSmoke',workspace/'plugins/StructureSmoke')
(workspace/'server.properties').write_text(f'motd=Structure Integration Test\nserver-port={args.port}\nenable-query=off\nenable-rcon=off\nlevel-name=structure_test\nlevel-seed=123456\ngamemode=1\nmax-players=1\n')
(workspace/'genisys.yml').write_text('config:\n version: 15\nnether:\n allow-nether: false\nastral:\n allow-astral: false\nender:\n allow-ender: false\nai:\n enable: false\nstructures:\n enabled: true\n')
(workspace/'pocketmine.yml').write_text('settings:\n language: eng\n async-workers: 2\n send-usage: false\ndebug:\n level: 1\n commands: true\nanonymous-statistics:\n enabled: false\nauto-report:\n enabled: false\nauto-updater:\n enabled: false\n')
print('Integration workspace:',workspace,flush=True)
command=[args.php,str(ROOT/'src/pocketmine/PocketMine.php'),f'--data={workspace}',f'--plugins={workspace}/plugins','--no-wizard','--disable-readline']
try:
    result=subprocess.run(command,cwd=ROOT,stdin=subprocess.DEVNULL,timeout=240)
except subprocess.TimeoutExpired:
    raise SystemExit(f'Test timed out; inspect {workspace}')
receipt_path=workspace/'structure-test-result.json'
if result.returncode or not receipt_path.is_file():
    raise SystemExit(f'Server test exited {result.returncode}; inspect {workspace}')
receipt=json.loads(receipt_path.read_text())
print(json.dumps(receipt,indent=2))
if not receipt.get('ok'):
    raise SystemExit(1)
