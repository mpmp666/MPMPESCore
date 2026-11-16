#!/usr/bin/env python3
"""Start an isolated real PHP server for AI/enchantment integration; keep its receipt."""
import argparse,json,shutil,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--port',type=int,default=19152)
p.add_argument('--php',default=str(ROOT/'bin/php7/bin/php'))
a=p.parse_args()
if not 1024<=a.port<=65535:p.error('invalid UDP port')
workspace=Path(tempfile.mkdtemp(prefix='mpmpescore-ai-enchant26-'))
shutil.copytree(ROOT/'tools/fixtures/AIEnchantSmoke',workspace/'plugins/AIEnchantSmoke')
(workspace/'server.properties').write_text(f'motd=AI Enchant 26.2 Integration\nserver-port={a.port}\nenable-query=off\nenable-rcon=off\nlevel-name=ai26_test\nlevel-seed=123456\nlevel-type=FLAT\ngamemode=1\ndifficulty=2\nmax-players=1\n')
(workspace/'genisys.yml').write_text('config:\n version: 15\nnether:\n allow-nether: false\nastral:\n allow-astral: false\nender:\n allow-ender: false\nai:\n enable: true\n mobgenerate: false\n creeper-explode-destroy-block: false\nstructures:\n enabled: false\n')
(workspace/'pocketmine.yml').write_text('settings:\n language: eng\n async-workers: 2\n send-usage: false\ndebug:\n level: 1\n commands: true\nanonymous-statistics:\n enabled: false\nauto-report:\n enabled: false\nauto-updater:\n enabled: false\n')
print('AI/enchantment test workspace:',workspace,flush=True)
command=[a.php,str(ROOT/'src/pocketmine/PocketMine.php'),f'--data={workspace}',f'--plugins={workspace}/plugins','--no-wizard','--disable-readline']
try:r=subprocess.run(command,cwd=ROOT,stdin=subprocess.DEVNULL,timeout=150)
except subprocess.TimeoutExpired:raise SystemExit(f'Integration timed out; inspect {workspace}')
receipt=workspace/'ai-enchant-test-result.json'
if r.returncode or not receipt.is_file():raise SystemExit(f'Integration exited {r.returncode}; inspect {workspace}')
data=json.loads(receipt.read_text())
print(json.dumps(data,indent=2))
if not data.get('ok'):raise SystemExit(1)
