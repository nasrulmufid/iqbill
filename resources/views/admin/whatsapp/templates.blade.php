@extends('layouts.app', ['title' => 'WhatsApp Message Templates'])

@section('content')
<div class="space-y-6">
    <x-page-header title="WhatsApp Reminder & Isolation Templates" subtitle="Customize message content using placeholder variables ({customer_name}, {package}, {payment_link}) and WhatsApp formatting (*Bold*, _Italic_, ~Strike~, `Mono`)." />

    {{-- WhatsApp Formatting & Placeholder Variables Guide --}}
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">WhatsApp Text Formatting (Markdown)</div>
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1">
                    <code class="font-mono text-slate-700">*BOLD*</code> &rarr; <strong class="font-bold text-slate-900">BOLD</strong>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1">
                    <code class="font-mono text-slate-700">_Italic_</code> &rarr; <em class="italic text-slate-900">Italic</em>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1">
                    <code class="font-mono text-slate-700">~Strike~</code> &rarr; <del class="line-through text-slate-600">Strike</del>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1">
                    <code class="font-mono text-slate-700">`Code`</code> &rarr; <code class="rounded bg-slate-200/70 px-1 font-mono">Code</code>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1">
                    <code class="font-mono text-slate-700">&gt; Quote</code>
                </span>
            </div>
        </div>

        <div class="border-t border-slate-100 pt-3">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2.5">Available Variables (Click to Copy)</div>
            <div class="flex flex-wrap gap-2">
                @foreach($placeholders as $var => $desc)
                    <button type="button"
                            onclick="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($var) }}); window.dispatchEvent(new CustomEvent('notify-copy'));"
                            title="{{ $desc }}"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-mono font-semibold text-indigo-700 hover:bg-indigo-100">
                        <span>{{ $var }}</span>
                        <span class="font-sans font-normal text-indigo-500">({{ $desc }})</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @foreach($templates as $tpl)
            <form method="POST" action="{{ route('admin.whatsapp.templates.update', $tpl) }}"
                  x-data="{ body: @js($tpl->body_template) }"
                  class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
                @csrf
                @method('PUT')
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-mono text-xs font-bold text-indigo-600">{{ $tpl->key }}</div>
                        <input type="text" name="label" value="{{ $tpl->label }}" required
                               class="mt-1 rounded-lg border-slate-300 text-sm font-semibold text-slate-900">
                    </div>
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                        <input type="checkbox" name="is_active" value="1" @checked($tpl->is_active) class="rounded border-slate-300 text-indigo-600">
                        Active
                    </label>
                </div>

                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-1.5">
                        <label class="block text-xs font-medium text-slate-600">Template Body</label>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="body = window.waWrapSelection($refs.tplInput, '*', '*', 'BOLD')" title="Bold (*text*)"
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-bold text-slate-700 hover:bg-slate-100">B</button>
                            <button type="button" @click="body = window.waWrapSelection($refs.tplInput, '_', '_', 'Italic')" title="Italic (_text_)"
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs italic font-semibold text-slate-700 hover:bg-slate-100">I</button>
                            <button type="button" @click="body = window.waWrapSelection($refs.tplInput, '~', '~', 'Strike')" title="Strikethrough (~text~)"
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs line-through font-semibold text-slate-700 hover:bg-slate-100">S</button>
                            <button type="button" @click="body = window.waWrapSelection($refs.tplInput, '`', '`', 'code')" title="Monospace (`code`)"
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-slate-700 hover:bg-slate-100">&lt;/&gt;</button>
                        </div>
                    </div>
                    <textarea x-ref="tplInput" x-model="body" name="body_template" rows="4" required class="w-full rounded-xl border-slate-300 text-sm font-mono">{{ $tpl->body_template }}</textarea>
                </div>

                <div class="rounded-2xl bg-[#e5ddd5] border border-slate-200 p-3.5">
                    <div class="text-[11px] font-semibold text-slate-600 mb-1.5">WhatsApp Bubble Preview:</div>
                    <div class="rounded-xl rounded-tl-sm bg-[#dcf8c6] px-3.5 py-2.5 shadow-sm text-xs text-slate-800 break-words"
                         x-html="window.waRenderSamplePreview(body)">{!! $previews[$tpl->id] ?? '' !!}</div>
                </div>

                <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                    Save Template
                </button>
            </form>
        @endforeach
    </div>
</div>

<script>
    window._waSampleVars = @js($sampleVars);

    window.waWrapSelection = function (el, prefix, suffix, fallbackText) {
        if (!el) return '';
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        const selected = el.value.slice(start, end) || fallbackText;
        const inserted = prefix + selected + suffix;
        el.value = el.value.slice(0, start) + inserted + el.value.slice(end);
        el.selectionStart = start + prefix.length;
        el.selectionEnd = start + prefix.length + selected.length;
        el.focus();
        return el.value;
    };

    window.waRenderSamplePreview = function (rawText) {
        if (!rawText || !rawText.trim()) return '';
        let text = rawText.replace(/\r\n?/g, '\n').replace(/\x1A/g, '');

        // Case-insensitive substitution for {placeholder} variables
        const lowerMap = {};
        Object.keys(window._waSampleVars || {}).forEach((k) => {
            lowerMap[k.toLowerCase()] = String(window._waSampleVars[k] ?? '').replace(/\x1A/g, '');
        });
        text = text.replace(/\{\s*([a-zA-Z0-9_]+)\s*\}/g, (full, name) => {
            const key = '{' + name.toLowerCase() + '}';
            return Object.prototype.hasOwnProperty.call(lowerMap, key) ? lowerMap[key] : full;
        });

        let escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        const tokenMap = new Map();
        const stash = (html) => {
            const key = '\x1ATOKEN' + tokenMap.size + '\x1A';
            tokenMap.set(key, html);
            return key;
        };

        escaped = escaped.replace(/```([\s\S]+?)```/g, (_, inner) =>
            stash('<code class="rounded bg-black/5 px-1.5 py-0.5 font-mono text-[12px]">' + inner + '</code>')
        );
        escaped = escaped.replace(/`([^`\n]+)`/g, (_, inner) =>
            stash('<code class="rounded bg-black/5 px-1.5 py-0.5 font-mono text-[12px]">' + inner + '</code>')
        );
        escaped = escaped.replace(/https?:\/\/[^\s<]+/gi, (url) => {
            const safeUrl = url.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            return stash('<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer" class="underline text-sky-700 break-all">' + url + '</a>');
        });

        escaped = escaped.replace(/(^|[\s("'\[~_>])\*([^\s*\n](?:[^*\n]*[^\s*\n])?)\*(?=$|[\s.,!?:;)"'\]~_<])/gm, '$1<strong class="font-bold">$2</strong>');
        escaped = escaped.replace(/(^|[\s("'\[*~>])_([^\s_\n](?:[^_\n]*[^\s_\n])?)_(?=$|[\s.,!?:;)"'\]*~<])/gm, '$1<em class="italic">$2</em>');
        escaped = escaped.replace(/(^|[\s("'\[*_>])~([^\s~\n](?:[^~\n]*[^\s~\n])?)~(?=$|[\s.,!?:;)"'\]*_<])/gm, '$1<del class="line-through text-slate-500">$2</del>');
        escaped = escaped.replace(/^&gt;\s?(.+)$/gm, '<span class="block border-l-2 border-emerald-600/50 pl-2.5 text-slate-600">$1</span>');

        escaped = escaped.replace(/\x1ATOKEN\d+\x1A/g, (k) => tokenMap.get(k) ?? '');

        return escaped.replace(/\n/g, '<br>');
    };
</script>
@endsection
