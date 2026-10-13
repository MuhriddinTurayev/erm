@php $st = $w->state(); @endphp<span class="badge {{ ['active' => 'b-ok', 'trial' => 'b-info', 'expired' => 'b-warn', 'suspended' => 'b-bad'][$st] }}">{{ $w->stateLabel() }}</span>
