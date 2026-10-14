{{-- Карточка «ИИ-анализ»: @include('partials.insights', ['topic' => 'company', 'o' => $pid ?? null]) — содержимое загружает assets/insights.js --}}
<div class="card ai-card {{ $class ?? '' }}" data-insights="{{ $topic }}" @if (! empty($o)) data-o="{{ $o }}" @endif>
  <div class="card-h">
    <h3><span class="ai-mark">{!! ic('sparkles') !!}</span>{{ $title ?? __('ИИ-анализ') }}</h3>
    <div class="flex" style="gap:6px"><span class="ai-src" data-ai-src></span><button type="button" class="icon-btn" data-ai-refresh title="{{ __('Обновить разбор') }}" aria-label="{{ __('Обновить разбор') }}" style="width:34px;height:34px">{!! ic('refresh-cw', '', 16) !!}</button></div>
  </div>
  <div data-ai-body><div class="ai-load"><i></i>{{ __('Смотрю цифры…') }}</div></div>
</div>
@once
@push('scripts')
<script>window.OFTOB_AI_T = {!! json_encode(['ai' => __('Разбор ИИ'), 'rules' => __('Выводы по правилам'), 'thinking' => __('ИИ анализирует цифры…'), 'go' => __('Открыть'), 'err' => __('Не удалось загрузить разбор'), 'at' => __('обновлено')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
@endpush
@endonce
