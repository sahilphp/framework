<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SahilPHP')</title>
    @asset_client
    @asset_css('main')
</head>
<body>
    <main>
        @yield('content')
    </main>
    @asset_js('main')
</body>
</html>
