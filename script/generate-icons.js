const { createCanvas } = require('canvas');
const fs = require('fs');
const path = require('path');

function generateIcon(size) {
  const canvas = createCanvas(size, size);
  const ctx    = canvas.getContext('2d');
  const radius = size * 0.22;

  // Background
  ctx.fillStyle = '#00c9a7';
  ctx.beginPath();
  ctx.roundRect(0, 0, size, size, radius);
  ctx.fill();

  // Letter R
  ctx.fillStyle = '#0a1f1c';
  ctx.font      = `900 ${size * 0.58}px sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText('R', size / 2, size * 0.52);

  return canvas.toBuffer('image/png');
}

const publicDir = path.join(__dirname, '..', 'public');
if (!fs.existsSync(publicDir)) fs.mkdirSync(publicDir, { recursive: true });

[72, 96, 128, 144, 152, 192, 384, 512].forEach(function(size) {
  const buf  = generateIcon(size);
  const file = path.join(publicDir, 'icon-' + size + '.png');
  fs.writeFileSync(file, buf);
  console.log('Created ' + file);
});