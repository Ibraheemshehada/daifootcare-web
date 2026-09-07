{{--
    The support and privacy pages, rendered on the server.

    These two URLs must return their full text in the first HTML response. The
    App Store requires a working support URL and a privacy policy URL, and a
    reviewer — or a crawler that does not execute JavaScript — sees only what
    the server sent. The SPA version of these pages returned an empty <body>
    with a correct <title>, which looks reachable to anything checking a status
    code and is a blank page to a person.

    The text is read from the same locale JSON the Vue application uses, so
    there is one source for it and the two renderings cannot drift.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $t['title'] }} — DiaFootCare</title>
    <meta name="description" content="{{ $t['intro'] }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="alternate" hreflang="en" href="{{ url()->current() }}?lang=en">
    <link rel="alternate" hreflang="ar" href="{{ url()->current() }}?lang=ar">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#1877F2">

    {{-- Deliberately not @vite. No build step, no JavaScript: the page must
         render identically with scripting disabled. --}}
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI",
                  system-ui, "Noto Naskh Arabic", sans-serif;
            color: #0f172a;
            background: #fff;
        }
        header { border-bottom: 1px solid #e2e8f0; }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 0 1.25rem; }
        header .wrap { display: flex; align-items: center; gap: .75rem; height: 4rem; }
        .mark {
            width: 2.25rem; height: 2.25rem; border-radius: .75rem;
            background: #0e7490; color: #fff; display: flex;
            align-items: center; justify-content: center; font-size: 1.1rem;
        }
        .brand { font-weight: 600; color: inherit; text-decoration: none; }
        main { padding: 2.5rem 0 3rem; }
        h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 .5rem; }
        .updated { color: #64748b; font-size: .9rem; margin: 0 0 1.75rem; }
        h2 { font-size: 1.15rem; margin: 2rem 0 .5rem; }
        p { margin: 0 0 .9rem; }
        .contact {
            margin-top: 2.5rem; padding: 1.25rem;
            border: 1px solid #e2e8f0; border-radius: .75rem; background: #f8fafc;
        }
        .contact a { color: #0e7490; font-weight: 500; }
        nav.foot {
            margin-top: 2.5rem; padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0; font-size: .9rem;
        }
        nav.foot a { color: #475569; margin-inline-end: 1.5rem; }
        @media (prefers-color-scheme: dark) {
            body { color: #e2e8f0; background: #020617; }
            header, nav.foot, .contact { border-color: #1e293b; }
            .contact { background: #0f172a; }
            .updated { color: #94a3b8; }
            nav.foot a { color: #94a3b8; }
        }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <span class="mark">◎</span>
            <a class="brand" href="/">DiaFootCare</a>
        </div>
    </header>

    <main class="wrap">
        <h1>{{ $t['title'] }}</h1>
        <p class="updated">{{ $t['updated'] }}</p>
        <p>{{ $t['intro'] }}</p>

        @foreach ($t['sections'] as $section)
            <h2>{{ $section['heading'] }}</h2>
            @foreach ($section['body'] as $line)
                <p>{{ $line }}</p>
            @endforeach
        @endforeach

        <section class="contact">
            <h2 style="margin-top:0">{{ $legal['contact_heading'] }}</h2>
            <p>{{ $legal['contact_body'] }}</p>
            <a href="mailto:{{ $email }}">{{ $email }}</a>
        </section>

        <nav class="foot">
            <a href="/">{{ $legal['nav_home'] }}</a>
            <a href="/support{{ $qs }}">{{ $titles['support'] }}</a>
            <a href="/privacy{{ $qs }}">{{ $titles['privacy'] }}</a>
            <a href="{{ $locale === 'ar' ? '?lang=en' : '?lang=ar' }}">{{ $locale === 'ar' ? 'English' : 'العربية' }}</a>
        </nav>
    </main>
</body>
</html>
