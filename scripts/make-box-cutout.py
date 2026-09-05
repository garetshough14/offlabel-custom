"""User-authorized local background removal; never uploads the product image."""
from pathlib import Path
import os
import tempfile
from PIL import Image
import numpy as np
from rembg import remove, new_session
from scipy.ndimage import label, binary_dilation

root = Path(__file__).resolve().parents[1]
os.environ.setdefault('U2NET_HOME', str(Path(tempfile.gettempdir()) / 'olr-box-cutout-models'))
source = root / 'output/site-controls/build-box-draft-not-transparent.png'
target = root / 'wordpress-plugins/off-label-site-controls/assets/build-box-transparent-v1.png'
original = Image.open(source).convert('RGB')
session = new_session('isnet-general-use', providers=['CPUExecutionProvider'])
result = remove(original, session=session).convert('RGBA')
alpha = np.asarray(result.getchannel('A'))
# Discard low-confidence checkerboard residue; make retained label/box interiors
# fully opaque while keeping a graded alpha transition at the silhouette.
alpha = np.clip((alpha.astype(np.float32) - 100) * (255 / 100), 0, 255).astype(np.uint8)
# Clear the enclosed background gap between the left vial and the box flap.
# A seed constrained to this inspected hole leaves the label and glass intact.
rgb = np.asarray(original)
light_neutral = (rgb.min(2) > 215) & ((rgb.max(2).astype(float) - rgb.min(2)) < 18)
components, _ = label(light_neutral)
hole = components == components[730, 390]
yy, xx = np.where(hole)
assert hole.sum() < 5000 and xx.min() > 300 and xx.max() < 450 and yy.min() > 650 and yy.max() < 800
alpha[binary_dilation(hole, iterations=1)] = 0
result.putalpha(Image.fromarray(alpha))
assert (alpha == 0).mean() > .2, 'Background is not transparent'
assert (alpha >= 240).mean() > .15, 'Objects were not retained'
result.save(target)
for name, color in [('bone', '#f5f4f0'), ('dark', '#333333')]:
    background = Image.new('RGBA', result.size, color)
    background.alpha_composite(result)
    background.convert('RGB').save(root / f'output/site-controls/box-cutout-{name}.png')
print(f'RGBA {result.size}: {(alpha == 0).mean():.1%} fully transparent; saved {target}')
