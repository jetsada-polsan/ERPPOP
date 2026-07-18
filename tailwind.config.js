/**
 * Static Tailwind build for POPSTAR ERP - replaces the Play-CDN runtime
 * compiler (vendor/tailwindcss/tailwind.min.js) that recompiled CSS in the
 * browser on every page load.
 *
 * Rebuild after adding new Tailwind classes in any blade file:
 *   ..\tools\tailwindcss.exe -c tailwind.config.js -i resources\css\tailwind-input.css -o public\vendor\tailwindcss\tailwind.min.css --minify
 * (run from the app/ directory)
 */
module.exports = {
  content: ['./resources/views/**/*.blade.php'],
  // preflight คงไว้ (ค่า default) เพราะตัว Play CDN เดิมก็ inject preflight อยู่แล้ว
  // ทุกหน้า render บน cascade แบบนั้นมาตลอด - ปิดแล้วหน้าตาจะเปลี่ยน
  theme: { extend: {} },
  plugins: [],
};
