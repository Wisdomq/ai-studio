<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Studio</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: {
                    sans: ['DM Sans', 'sans-serif'],
                    mono: ['DM Mono', 'monospace'],
                },
                colors: {
                    forest: {
                        50:  '#f0faf0',
                        100: '#dcf5dc',
                        200: '#baeaba',
                        300: '#86d886',
                        400: '#4ec14e',
                        500: '#2da62d',
                        600: '#1f8a1f',
                        700: '#196b19',
                        800: '#165516',
                        900: '#124512',
                    },
                    sage: {
                        50:  '#f6faf6',
                        100: '#e8f4e8',
                        200: '#d1e9d1',
                        300: '#a8d1a8',
                        400: '#77b477',
                        500: '#4f974f',
                        600: '#3a7a3a',
                    },
                    cream: '#fafdf8',
                    parchment: '#f3f8f0',
                }
            }
        }
    }
    </script>
    <style>
        * { font-family: 'DM Sans', sans-serif; }

        body {
            background: #fafdf8;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(45,166,45,0.04) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(78,193,78,0.03) 0%, transparent 50%);
        }

        /* ── Typing indicator ─────────────────────────────── */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 10px 14px;
        }
        .typing-indicator span {
            width: 7px; height: 7px;
            background: #2da62d;
            border-radius: 50%;
            animation: typingBounce 1.2s infinite;
        }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-6px); opacity: 1; }
        }

        /* ── Skeleton loader ──────────────────────────────── */
        .skeleton {
            background: linear-gradient(90deg, #e8f4e8 25%, #d1e9d1 50%, #e8f4e8 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 6px;
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ── Pulse ring for active state ──────────────────── */
        .pulse-ring::before {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: inherit;
            border: 2px solid #2da62d;
            animation: pulseRing 2s ease-out infinite;
        }
        @keyframes pulseRing {
            0% { opacity: 0.8; transform: scale(1); }
            100% { opacity: 0; transform: scale(1.08); }
        }

        /* ── Fade-in for new elements ─────────────────────── */
        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Slide-in for cards ───────────────────────────── */
        .slide-in {
            animation: slideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Message bubble ───────────────────────────────── */
        .msg-ai .bubble {
            background: white;
            border: 1px solid #d1e9d1;
            border-radius: 2px 16px 16px 16px;
        }
        .msg-user .bubble {
            background: #2da62d;
            color: white;
            border-radius: 16px 2px 16px 16px;
            margin-left: auto;
        }

        /* ── Step status badges ───────────────────────────── */
        .badge-pending    { background: #f3f8f0; color: #4f974f; border: 1px solid #d1e9d1; }
        .badge-running    { background: #fefce8; color: #a16207; border: 1px solid #fde68a; }
        .badge-approval   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
        .badge-completed  { background: #dcfce7; color: #166534; border: 1px solid #6ee7b7; }
        .badge-failed     { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

        /* ── Scrollbar ────────────────────────────────────── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #a8d1a8; border-radius: 3px; }

        /* ── Input focus ──────────────────────────────────── */
        textarea:focus, input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(45,166,45,0.15);
        }

        /* ── Progress bar ─────────────────────────────────── */
        .progress-bar {
            height: 3px;
            background: linear-gradient(90deg, #2da62d, #4ec14e);
            border-radius: 2px;
            animation: progressPulse 2s ease-in-out infinite;
            transform-origin: left;
        }
        @keyframes progressPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
    </style>
</head>
<body class="min-h-screen text-gray-800 antialiased">
    @yield('content')
</body>
</html>