# README 视觉素材

这套素材以「方块切面的 M + 六个连接节点」为图标，表达 MPMPESCore 的方块世界与六版本互通。墨绿背景、薄荷绿与青蓝用于统一项目图标、封面和协议示意图；小尺寸图标保留醒目的 M 轮廓。

| 文件 | 用途 | 尺寸 |
| --- | --- | --- |
| `logo.svg` / `logo.png` | 项目图标、仓库头像 | 512 × 512 |
| `banner.svg` / `banner.png` | README 封面 | 1600 × 620 |
| `compatibility.svg` / `compatibility.png` | 六版本互通示意图 | 1600 × 720 |

SVG 是可编辑的矢量版本，PNG 是 README 使用的静态版本。素材均由本项目的绘图脚本直接绘制，无外部图片依赖、无远程字体、无生成式图像 API 调用。文字使用 `DejaVu Sans` 和通用无衬线字体回退；重绘时安装 DejaVu Sans 可保持字形一致。

封面与协议图是品牌插画和原理示意，**不是游戏截图**。实际方块几何预览沿用 [`../structure-preview.png`](../structure-preview.png)，其来源与渲染说明见 [建筑移植说明](../../STRUCTURES.md)。

## 重新生成

在项目根目录运行，建议使用独立 Python 虚拟环境：

```bash
python3 -m venv /tmp/mpmpescore-artwork
/tmp/mpmpescore-artwork/bin/python -m pip install cairosvg pillow
/tmp/mpmpescore-artwork/bin/python tools/render_readme_assets.py
```

CairoSVG 需要系统 Cairo 库。常规阅读 README 无需安装任何绘图依赖；依赖仅用于重绘。

修改 [`tools/render_readme_assets.py`](../../tools/render_readme_assets.py) 后重新生成即可同步更新三个 SVG 及对应 PNG。图标和配图作为本仓库原创素材，随项目以 LGPL-3.0-or-later 分发；项目名不表示与 Mojang 或 Microsoft 存在官方关联。
