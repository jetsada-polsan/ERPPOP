<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PopCentral · Greenfield Starter</title>
    {{-- โหลดเฉพาะ bundle greenfield (Tailwind v4) หน้านี้หน้าเดียว —
         ไม่โหลด AdminLTE/vendored tailwind ของหลังบ้าน จึงไม่ชนกัน --}}
    @vite(['resources/css/app.css', 'resources/js/greenfield/main.ts'])
</head>
<body class="bg-canvas">
    <div id="greenfield-app"></div>
</body>
</html>
