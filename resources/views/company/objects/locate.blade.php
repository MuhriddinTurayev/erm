@php
  $public = $public ?? false;
  $auto = $auto ?? false;
  $aiUrl = $aiUrl ?? null;
  $inner = $inner ?? [];
  $canUnits = $canUnits ?? false;
  $cal = (array) ($model?->meta['locate']['blocks'] ?? []);
  $blocks = $data['blocks'];
  $multi = count($blocks) > 1;
  $T = \App\Services\Locate::innerTexts() + [
    'kv' => __('Кв.'), 'fl' => __('этаж'), 'en' => __('подъезд'), 'rooms' => __('-комн.'), 'm2' => __('м²'), 'k' => __('Коммерция'),
    'approx' => __('Примерное место: корпус ещё не привязан к 3D-модели.'), 'setup' => __('Привязать'), 'saved' => __('Сохранено'),
    'err' => __('Не удалось сохранить'), 'load' => __('Загружаю 3D-модель…'), 'fail' => __('Не удалось открыть 3D-модель'), 'none' => __('Выберите квартиру'), 'pickHint' => __('Наведите на квартиру на фасаде — нажмите, чтобы выбрать.'),
    'st' => ['free' => __('Свободна'), 'booked' => __('Бронь'), 'sold' => __('Продана'), 'reserve' => __('Не в продаже')],
    // автопривязка
    'aiBusy' => __('ИИ изучает модель: где дом, этажи, входы в подъезды…'), 'aiRun' => __('ИИ расставляет квартиры на модели…'),
    'aiDone' => __('Квартиры расставил ИИ. Проверьте номера на фасаде; если что-то не так — поправьте и сохраните.'),
    'geoDone' => __('Квартиры расставлены по форме модели. Проверьте номера на фасаде и сохраните.'),
    'aiOff' => __('ИИ не подключён — квартиры расставлены по форме модели.'), 'aiLimit' => __('Лимит ИИ на месяц исчерпан — квартиры расставлены по форме модели.'),
    'aiFail' => __('ИИ сейчас не ответил — квартиры расставлены по форме модели. Повторите позже кнопкой «Расставить квартиры с ИИ».'),
    'aiAuto' => __('Квартиры расставил ИИ.'), 'check' => __('Проверить привязку'), 'autoSaved' => __('Привязка сохранена'),
    'wFloors' => __('В модели :m эт., а в объекте :n эт. Квартиры показаны на этаже модели той же высоты (несколько этажей объекта — на одном этаже модели). Для точного показа загрузите модель именно этого дома.'),
    'wDoors' => __('Входов на фасаде: :d, подъездов в объекте: :e. Подъезды поделены поровну — передвиньте синие точки к дверям.'),
    'wNotBuilding' => __('Похоже, в модели не один дом (район, земля или интерьер). Лучше загрузить модель только самого здания.'),
    'wBlocks' => __('В модели найдено меньше домов, чем корпусов в объекте — поставьте рамки остальных корпусов вручную.'),
    'fail2' => __('Не получилось разобрать модель — поставьте рамку вручную.'),
    // разметка квартир и интерьер
    'kinds' => ['interior' => __('Интерьер квартиры 3D'), 'pano' => __('Панорама 360°'), 'photo' => __('Рендер / фото'), 'plan' => __('Планировка')],
    'noInner' => __('Интерьера этой квартиры ещё нет. Загрузите 3D-модель (GLB, FBX, OBJ…), панораму 360° или фото комнат.'),
    'forType' => __('Интерьер покажется у всех квартир этой планировки: :t.'), 'forRooms' => __('Интерьер покажется у всех :r-комнатных квартир без своей планировки.'),
    'upBusy' => __('Загружаю…'), 'upDone' => __('Загружено'), 'upFail' => __('Ошибка загрузки'), 'unitSaved' => __('Квартира сохранена'),
    'realloc' => __('Заново расставить все квартиры по окнам? Ваша ручная разметка этого корпуса сбросится.'),
    'convert' => __('Квартиры объекта сейчас рассчитаны по параметрам здания. Чтобы поменять одну квартиру, они будут переведены в перечень помещений (номера и сделки сохранятся). Продолжить?'),
    'placedHand' => __('Стоит на окнах, которые отметили вручную.'), 'placedAuto' => __('Стоит на окнах по числу комнат (ИИ).'), 'placedNone' => __('Стоит на равной доле этажа — протяните мышью по её окнам.'),
    'steps' => ['frame' => __('Шаг 1. Рамка обнимает дом от земли до крыши — по ней считаются этажи и подъезды. ИИ ставит её сам; проверьте и поправьте.'),
      'units' => __('Шаг 2. Слева — фасад дома. Выберите квартиру над ним и щёлкайте по её окнам — они окрасятся в её цвет; ещё щелчок — окно убрать. Окна сзади и на торцах — вкладки над фасадом.'),
      'inner' => __('Шаг 3. Что внутри: 3D-интерьер, панорамы 360° комнат, рендеры и план — для каждой планировки.')],
    'hintSelect' => __('Щёлкните квартиру на фасаде или выберите в списке.'),
    'hintPaint' => __('Щёлкайте по окнам выбранной квартиры: щелчок — окно добавить, ещё щелчок — убрать. Можно протянуть мышью. Окна сзади — кнопка «Сзади».'),
    'pcs' => __('кв.'), 'sF' => __('спереди'), 'sB' => __('сзади'), 'sR' => __('правый торец'), 'sL' => __('левый торец'), 'winsOn' => __('Окна:'),
    'inside' => __('Внутри'), 'nothing' => __('пока ничего'), 'look' => __('Смотреть'), 'addInner' => __('Добавить'), 'flats' => __('кв.'),
    'kindsShort' => ['interior' => __('3D'), 'pano' => __('360°'), 'photo' => __('Фото'), 'plan' => __('План')],
    'upTitle' => ['interior' => __('3D-интерьер: GLB, FBX, OBJ, DAE, 3DS, ZIP…'), 'pano' => __('Панорамы 360° комнат: картинка вдвое шире высоты (2:1), можно несколько'), 'photo' => __('Рендеры и фото комнат, можно несколько'), 'plan' => __('План квартиры: PDF или картинка')],
    'noLayouts' => __('В объекте пока нет квартир.'), 'noInnerLay' => __('Для этой планировки ещё ничего нет — загрузите 3D, панорамы 360°, фото или план кнопками выше.'),
    'notPano' => __('«:f» — не панорама 360° (у неё ширина должна быть ровно вдвое больше высоты). Загрузить как обычное фото?'),
    'winsN' => __('окон: :n'), 'freeWin' => __('свободное окно'), 'noFlats' => __('на этом этаже нет квартир'),
    'edit' => __('Изменить'), 'del' => __('Удалить'), 'editFile' => __('Файл интерьера'), 'fileName' => __('Название'), 'roomName' => __('Комната на панораме'),
    'forLayout' => __('Для какой планировки'), 'forAll' => __('Для всех планировок'), 'imgsNot3d' => __('Вы выбрали картинки, а не 3D-модель. Загрузить их как рендеры и фото интерьера (снимки 2:1 — как панорамы 360°)?'), 'cancel' => __('Отмена'), 'save' => __('Сохранить'), 'delFileQ' => __('Удалить «:f»? Файл пропадёт и у покупателей.'),
    'noInnerView' => __('Для этой планировки интерьера пока нет.'),
    'delModelQ' => __('Удалить эту 3D-модель здания? Её привязка к квартирам пропадёт, покупатели её больше не увидят. Интерьеры квартир останутся.'),
    'pickFirst' => __('Сначала выберите квартиру: щёлкните её на фасаде или в списке.'), 'findWin' => __('Ищу окна на фасаде…'),
  ];
  $innerT = ['view' => $viewUrl ?? null, 'file' => $fileUrl ?? null];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>{{ __('Квартира в 3D') }} — {{ $p['name'] ?? '' }}</title>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/"}}</script>
<style>
  html,body{height:100%;margin:0;overflow:hidden;background:var(--bg)}
  .lc{position:fixed;inset:0;background:radial-gradient(circle at 50% 25%,color-mix(in srgb,var(--accent) 10%,var(--bg)),var(--bg) 70%)}
  .lc canvas{display:block;touch-action:none}
  .lc-labels{position:absolute;inset:0;pointer-events:none}
  .lc-pin{display:flex;flex-direction:column;align-items:center;gap:1px;background:var(--accent);color:#fff;border-radius:12px;padding:7px 12px;box-shadow:0 10px 26px -8px rgba(76,47,214,.7);font:600 12px var(--font);white-space:nowrap;position:relative}
  .lc-pin b{font:700 14px var(--font-head)}
  .lc-pin span{opacity:.9;font-weight:500}
  .lc-pin::after{content:'';position:absolute;left:50%;bottom:-6px;width:12px;height:12px;background:var(--accent);transform:translateX(-50%) rotate(45deg);border-radius:2px}
  .lc-entr{font:700 13px var(--font-head);color:#fff;background:var(--accent-2);border-radius:99px;padding:4px 12px;box-shadow:0 6px 16px -6px rgba(59,130,246,.7);white-space:nowrap;pointer-events:none}
  .lc-leg{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;font-size:12.5px;color:var(--text-2)}.lc-leg span{display:inline-flex;gap:6px;align-items:center}.lc-leg i{width:11px;height:11px;border-radius:3px}
  .lc-num{font:700 11px var(--font);color:#fff;background:rgba(31,29,54,.72);border-radius:6px;padding:1px 5px;pointer-events:none}
  .lc-card{position:absolute;left:16px;top:16px;z-index:5;max-width:min(420px,calc(100% - 32px));background:color-mix(in srgb,var(--surface) 92%,transparent);backdrop-filter:blur(12px);border:1px solid var(--border-2);border-radius:14px;padding:12px 16px;box-shadow:var(--sh)}
  .lc-card a{font-size:13px;color:var(--muted);display:inline-flex;gap:6px;align-items:center;text-decoration:none;margin-bottom:6px}
  .lc-card b{display:block;font:700 16px var(--font-head)}
  .lc-card .lc-u{margin-top:6px;font-size:14px;color:var(--text-2)}
  .lc-card .lc-u strong{color:var(--accent-text);font-family:var(--font-head)}
  .lc-note{margin-top:8px;font-size:12.5px;color:var(--warn-text);background:var(--warn-soft);border-radius:9px;padding:6px 9px}
  .lc-note.ai{color:var(--accent-text);background:var(--accent-soft)}
  .lc-note a{color:inherit;font-weight:700;text-decoration:underline;margin:0}
  .lc-bar{position:absolute;left:50%;bottom:16px;transform:translateX(-50%);z-index:5;display:flex;gap:4px;background:color-mix(in srgb,var(--surface) 92%,transparent);backdrop-filter:blur(12px);border:1px solid var(--border-2);border-radius:14px;padding:5px;box-shadow:var(--sh);max-width:calc(100% - 24px);overflow-x:auto}
  .lc-bar button,.lc-bar a{height:36px;border:0;background:none;color:var(--text);border-radius:10px;padding:0 12px;display:inline-flex;gap:7px;align-items:center;font:600 13px var(--font);cursor:pointer;white-space:nowrap;text-decoration:none}
  .lc-bar button:hover,.lc-bar a:hover{background:var(--hover);color:var(--accent-text)}
  .lc-bar button.on{background:var(--accent);color:#fff}
  .lc-load{position:absolute;inset:0;display:grid;place-items:center;color:var(--muted);font-size:14px;pointer-events:none;z-index:4}
  .lc-load div{display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;max-width:320px}
  .lc-load i{width:30px;height:30px;border-radius:50%;border:3px solid var(--accent-soft);border-top-color:var(--accent);animation:spin .8s linear infinite}
  .lc-load em{font-style:normal;font-variant-numeric:tabular-nums}
  .lc-busy{position:absolute;inset:0;z-index:7;display:grid;place-items:center;background:color-mix(in srgb,var(--bg) 45%,transparent);backdrop-filter:blur(2px)}
  .lc-busy>div{display:flex;flex-direction:column;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--border-2);border-radius:16px;padding:20px 26px;box-shadow:var(--sh-lg);max-width:340px;text-align:center;font-size:14px;color:var(--text-2)}
  .lc-busy i{width:30px;height:30px;border-radius:50%;border:3px solid var(--accent-soft);border-top-color:var(--accent);animation:spin .8s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .lc-empty{position:absolute;inset:0;display:grid;place-items:center;text-align:center;padding:24px;color:var(--muted)}
  .lc-empty>div{max-width:420px;display:grid;gap:12px;justify-items:center}
  .lc-empty .ic{width:40px;height:40px;color:var(--faint)}
  /* настройка рамки */
  .lc-side{position:absolute;right:16px;top:16px;bottom:16px;z-index:6;width:330px;overflow:auto;background:var(--surface);border:1px solid var(--border-2);border-radius:16px;box-shadow:var(--sh-lg);padding:16px;display:flex;flex-direction:column;gap:14px}
  .lc-side h3{font-size:15.5px;display:flex;gap:8px;align-items:center;margin:0}.lc-side h3 .ic{color:var(--accent)}
  .lc-side p{margin:0;font-size:13px;color:var(--muted);line-height:1.5}
  .lc-side .seg{display:flex;gap:3px;background:var(--sunken);border-radius:11px;padding:3px}
  .lc-side .seg button{flex:1;height:32px;border:0;background:none;border-radius:8px;font:600 12.5px var(--font);color:var(--muted);cursor:pointer;padding:0 6px}
  .lc-side .seg button.on{background:var(--surface);color:var(--accent-text);box-shadow:var(--sh-sm),0 0 0 1px var(--border)}
  .lc-side .row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
  .lc-side .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .lc-side .field input,.lc-side .field select{height:36px}
  .lc-side .field>span{font-size:12px}
  .lc-side .btn{height:auto;min-height:36px;padding:6px 10px;white-space:normal;line-height:1.25;text-align:center}
  .lc-side .row2.one{grid-template-columns:1fr}
  .lc-sec{display:grid;gap:8px;padding-top:12px;border-top:1px solid var(--border)}
  .lc-lab{font:700 12px var(--font);color:var(--text-2);text-transform:uppercase;letter-spacing:.04em}
  .lc-model select{height:36px;border:1px solid var(--border-2);border-radius:10px;background:var(--surface);color:var(--text);padding:0 8px;font:500 13px var(--font);min-width:0}
  .lc-model-n{font:600 13.5px var(--font);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .lc-ai{display:grid;gap:8px;padding:12px;border-radius:12px;background:linear-gradient(135deg,color-mix(in srgb,var(--accent) 12%,var(--surface)),var(--surface));border:1px solid color-mix(in srgb,var(--accent) 22%,var(--border))}
  .lc-ai-h{display:flex;gap:8px;align-items:center;font:700 14px var(--font-head);color:var(--accent-text)}
  .lc-ai p{color:var(--text-2)}
  .lc-warn{margin:0;padding:0;list-style:none;display:grid;gap:6px}
  .lc-warn li{font-size:12.5px;line-height:1.45;color:var(--warn-text);background:var(--warn-soft);border-radius:9px;padding:7px 9px}
  .lc-hint{font-size:12.5px!important;color:var(--text-2)!important}
  .lc-hint i{display:inline-block;width:10px;height:10px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px var(--border-2);vertical-align:-1px;margin:0 3px 0 2px}
  .lc-step{display:flex;gap:4px}.lc-step input{min-width:0;text-align:center}
  .lc-step button{width:34px;flex:none;height:36px;border:1px solid var(--border-2);background:var(--surface);border-radius:9px;font:700 16px var(--font);color:var(--text);cursor:pointer}
  .lc-step button:hover{border-color:var(--accent);color:var(--accent-text)}
  .lc-more summary{cursor:pointer;font:600 13px var(--font);color:var(--muted)}
  .lc-more[open] summary{margin-bottom:8px}
  .lc-foot{margin-top:auto;display:grid;gap:8px;padding-top:12px;border-top:1px solid var(--border)}
  /* разметка квартир */
  .lc-side{overflow-x:hidden}
  .lc-tab{display:grid;gap:14px;grid-template-columns:minmax(0,1fr)}.lc-tab[hidden]{display:none}
  .lc-ucard,.lc-inner,.lc-sec{grid-template-columns:minmax(0,1fr)}
  .lc-tabs{position:sticky;top:-16px;z-index:2;margin:-4px 0 0}
  .lc-tabs button{height:36px!important;font-size:13.5px!important}
  .lc-num.on{background:var(--accent);box-shadow:0 0 0 2px #fff}
  .lc-tabs button{display:inline-flex;gap:6px;align-items:center;justify-content:center}
  .lc-tabs i{font:700 11px var(--font);font-style:normal;width:18px;height:18px;border-radius:50%;display:inline-grid;place-items:center;background:var(--border-2);color:var(--text-2)}
  .lc-tabs button.on i{background:var(--accent);color:#fff}
  .lc-stepnote{margin:-4px 0 0!important;font-size:12.5px!important;color:var(--text-2)!important;background:var(--accent-soft);border-radius:10px;padding:8px 10px}
  .lc-mode button{display:inline-flex;gap:6px;align-items:center;justify-content:center;height:36px!important}
  .lc-mode button.on{background:var(--accent)!important;color:#fff!important}
  .lc-step input{padding:0 4px!important;text-align:center}
  .lc-step.sm button{width:32px;height:32px;font-size:15px}.lc-step.sm input{height:32px;width:52px;flex:none;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);font:600 14px var(--font)}
  .lc-wrows{display:grid;gap:6px}.lc-wrow{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:13px;color:var(--text-2)}
  .ic.up{transform:rotate(180deg)}
  .lc-inmini{display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12.5px;color:var(--text-2);padding-top:8px;border-top:1px dashed var(--border-2)}
  .lc-inmini b{color:var(--text)}.lc-inmini .btn{min-height:30px;padding:4px 10px}
  .lc-ilayouts{display:grid;gap:10px}
  .lc-lay{border:1px solid var(--border-2);border-radius:12px;padding:10px;display:grid;gap:8px;background:var(--surface)}
  .lc-lay.cur{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-soft)}
  .lc-lay-h{display:flex;justify-content:space-between;gap:8px;align-items:baseline}.lc-lay-h b{font:700 14px var(--font-head)}.lc-lay-h small{color:var(--muted);font-size:12px;white-space:nowrap}
  .lc-lay-up{display:grid;grid-template-columns:repeat(4,1fr);gap:4px}
  .lc-lay-up button{min-height:46px;border:1px dashed var(--border-2);background:var(--sunken);border-radius:9px;cursor:pointer;display:grid;place-items:center;gap:2px;font:600 11px var(--font);color:var(--text-2);padding:4px 2px;text-align:center}
  .lc-lay-up button:hover{border-color:var(--accent);color:var(--accent-text)}
  .lc-lay-up button .ic{width:16px;height:16px}
  .lc-lay-up button.has{border-style:solid;background:var(--accent-soft);color:var(--accent-text)}
  /* шаг 2: плоский фасад */
  .lc-fac{position:absolute;left:16px;top:16px;bottom:16px;right:362px;z-index:6;background:var(--surface);border:1px solid var(--border-2);border-radius:16px;box-shadow:var(--sh-lg);display:flex;flex-direction:column;overflow:hidden}
  .lc-fac[hidden]{display:none}
  .lc-fac-bar{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--border);flex-wrap:wrap}
  .lc-fac-bar .seg{display:flex;gap:3px;background:var(--sunken);border-radius:11px;padding:3px;flex-wrap:wrap}
  .lc-fac-bar .seg button{height:34px;border:0;background:none;border-radius:8px;font:600 13px var(--font);color:var(--muted);cursor:pointer;padding:0 12px}
  .lc-fac-bar .seg button.on{background:var(--surface);color:var(--accent-text);box-shadow:var(--sh-sm),0 0 0 1px var(--border)}
  .lc-fac-fl{display:flex;align-items:center;gap:6px}.lc-fac-fl b{font:700 15px var(--font-head);min-width:90px;text-align:center}
  .lc-fac-fl button{width:36px;height:36px;border:1px solid var(--border-2);background:var(--surface);border-radius:10px;cursor:pointer;display:grid;place-items:center;color:var(--text)}
  .lc-fac-fl button:hover{border-color:var(--accent);color:var(--accent-text)}
  .lc-fac-apts{display:flex;gap:8px;padding:10px 12px;overflow-x:auto;border-bottom:1px solid var(--border);min-height:62px}
  .lc-apt{flex:none;display:flex;gap:8px;align-items:center;border:2px solid transparent;background:var(--sunken);border-radius:12px;padding:5px 12px 5px 5px;cursor:pointer;font:600 13px var(--font);color:var(--text);text-align:left}
  .lc-apt i{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;color:#fff;font:700 14px var(--font-head);font-style:normal}
  .lc-apt small{display:block;color:var(--muted);font-weight:500;font-size:11.5px}
  .lc-apt:hover{border-color:var(--border-2)}.lc-apt.on{border-color:var(--accent);background:var(--accent-soft)}
  .lc-fac-stage{flex:1;min-height:0;position:relative;display:grid;place-items:center;padding:14px;background:var(--sunken)}
  .lc-fac-pic{position:relative;box-shadow:var(--sh);border-radius:6px;overflow:hidden;user-select:none;touch-action:none}
  .lc-fac-pic img{display:block;width:100%;height:100%;pointer-events:none}
  .lc-fac-grid{position:absolute;inset:0}
  .fc{position:absolute;border:1px solid rgba(255,255,255,.9);box-shadow:inset 0 0 0 1px rgba(31,29,54,.25);cursor:pointer;display:grid;place-items:center;font:700 14px var(--font-head);color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)}
  .fc:hover{box-shadow:inset 0 0 0 3px #fff}
  .fc.free{background:rgba(255,255,255,.06)}.fc.free:hover{background:rgba(255,255,255,.35)}
  .fc.on{box-shadow:inset 0 0 0 3px #fff,0 0 0 2px var(--accent)}
  .fv{position:absolute;left:0;right:0;background:rgba(17,17,39,.42);cursor:pointer}
  .fv:hover{background:rgba(17,17,39,.28)}
  .fa{position:absolute;box-shadow:0 0 0 3px var(--accent);border-radius:2px;pointer-events:none}
  .lc-fac-load{position:absolute;inset:0;display:grid;place-items:center;background:color-mix(in srgb,var(--sunken) 70%,transparent)}
  .lc-fac-load i{width:30px;height:30px;border-radius:50%;border:3px solid var(--accent-soft);border-top-color:var(--accent);animation:spin .8s linear infinite}
  .lc-fac-back{position:absolute;left:50%;top:16px;transform:translateX(-50%);z-index:8;height:auto;min-height:40px}
  .lc-fac-back[hidden]{display:none}
  @media (max-width:760px){.lc-fac{left:8px;right:8px;top:8px;bottom:calc(50vh + 80px)}}
  .lc-pick{display:flex;gap:6px;min-width:0}.lc-pick select{flex:1;min-width:0;width:100%;height:38px}
  .lc-pick button{width:38px;flex:none;height:38px;border:1px solid var(--border-2);background:var(--surface);border-radius:10px;cursor:pointer;color:var(--text);display:grid;place-items:center}
  .lc-pick button:hover{border-color:var(--accent);color:var(--accent-text)}
  .lc-ucard{display:grid;gap:10px;padding:12px;border-radius:12px;border:1px solid var(--border-2);background:var(--sunken)}
  .lc-uhead b{display:flex;gap:8px;align-items:center;font:700 16px var(--font-head)}.lc-dot{width:14px;height:14px;border-radius:4px;flex:none}.lc-uhead small{color:var(--muted);font-size:12.5px}
  .lc-place{font-size:12.5px;color:var(--text-2);display:flex;gap:6px;align-items:flex-start}.lc-place .ic{flex:none;color:var(--accent);margin-top:1px}
  .lc-chk{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--text-2);cursor:pointer}.lc-chk input{margin-top:2px;accent-color:var(--accent)}
  .lc-inner{display:grid;gap:8px}
  .lc-ilist{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
  .lc-ilist button{position:relative;aspect-ratio:4/3;border:1px solid var(--border-2);border-radius:10px;background:var(--surface) center/cover no-repeat;cursor:pointer;display:grid;place-items:center;color:var(--muted);padding:0;overflow:hidden}
  .lc-ilist button:hover{border-color:var(--accent)}
  .lc-ilist button span{position:absolute;left:0;right:0;bottom:0;font:600 10.5px var(--font);color:#fff;background:linear-gradient(transparent,rgba(17,17,39,.8));padding:8px 5px 4px;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .lc-ilist button .ic{width:22px;height:22px}
  .lc-iempty{font-size:12.5px;color:var(--muted);padding:10px;border:1px dashed var(--border-2);border-radius:10px;text-align:center}
  .lc-prog{height:6px;border-radius:9px;background:var(--sunken);overflow:hidden}.lc-prog i{display:block;height:100%;width:0;background:var(--accent);transition:width .2s}
  .lc-ptext{font-size:12px;color:var(--muted)}
  /* «Внутри квартиры» — поверх 3D-дома */
  .lc-ov{position:fixed;inset:0;z-index:20;background:rgba(17,17,39,.55);backdrop-filter:blur(3px);display:grid;place-items:center;padding:20px}
  .lc-ov-in{position:relative;width:min(1200px,100%);height:min(820px,100%);background:var(--surface);border-radius:16px;overflow:hidden;box-shadow:var(--sh-lg)}
  .lc-ov-in iframe,.lc-ov-in img{width:100%;height:100%;border:0;display:block;object-fit:contain;background:var(--bg)}
  .lc-ov-x{position:absolute;right:12px;top:12px;z-index:2;width:40px;height:40px;border-radius:50%;border:1px solid var(--border-2);background:var(--surface);cursor:pointer;display:grid;place-items:center;color:var(--text);box-shadow:var(--sh)}
  body.embed .lc-ov{padding:8px}
  .lc-ov[hidden]{display:none}
  @media (max-width:760px){.lc-side{left:8px;right:8px;top:auto;bottom:70px;width:auto;max-height:50vh}.lc-card{left:8px;top:8px;padding:9px 12px}.lc-card b{font-size:14px}}
  body.embed .lc-card a.back,body.embed .lc-card>b{display:none}
  body.embed .lc-card{left:10px;top:10px;padding:8px 12px;border-radius:12px}
  body.embed .lc-card .lc-u{margin:0;font-size:13px}
  body.embed .lc-bar{bottom:10px;padding:4px}
  body.embed .lc-bar button,body.embed .lc-bar a{height:32px;padding:0 10px;font-size:12.5px}
</style>
</head>
<body class="{{ $embed ? 'embed' : '' }}">
<div class="lc" id="lc"></div>

<div class="lc-card">
  @unless ($embed)<a class="back" href="{{ $back }}">{!! ic('arrow-left', '', 14) !!}{{ $public ? __('Назад') : __('К объекту') }}</a>@endunless
  <b>{{ $p['name'] ?? '' }}</b>
  <div class="lc-u" id="lcU">{{ $calibrate ? __('Привязка корпусов к 3D-модели') : '' }}</div>
  <div class="lc-note" id="lcNote" hidden></div>
</div>

@if (! $model)
  <div class="lc-empty"><div>{!! ic('box') !!}
    @if ($public)
      <b>{{ __('3D-модель дома скоро появится') }}</b><span>{{ __('Пока посмотрите расположение квартиры на шахматке.') }}</span>
    @else
      <b>{{ __('3D-модель здания ещё не загружена') }}</b><span>{{ __('Загрузите её: Объекты и 3D → объект → «3D, панорамы, планировки» → «Фасад и здание 3D». После этого квартиры можно будет показывать прямо на доме.') }}</span>
      <a class="btn btn-p" href="/company/objects/{{ $pid }}?tab=media" target="_top">{!! ic('upload') !!}{{ __('Загрузить 3D-модель') }}</a>
    @endif
  </div></div>
@else
  <div class="lc-load" id="lcLoad"><div><i></i><span>{{ $T['load'] }}</span><em id="lcPct"></em></div></div>
  <div class="lc-busy" id="lcBusy" hidden><div><i></i><span id="lcBusyT">{{ $T['aiBusy'] }}</span></div></div>
  <div class="lc-bar" id="lcBar">
    <button type="button" data-act="unit" title="{{ __('Показать квартиру') }}">{!! ic('target', '', 16) !!}<span>{{ __('Квартира') }}</span></button>
    <button type="button" data-act="house" title="{{ __('Весь дом') }}">{!! ic('building-2', '', 16) !!}<span>{{ __('Весь дом') }}</span></button>
    <button type="button" data-act="inner" title="{{ __('Внутри квартиры') }}" hidden>{!! ic('sofa', '', 16) !!}<span>{{ __('Внутри') }}</span></button>
    @if ($canEdit && ! $calibrate)<a href="/company/objects/{{ $pid }}/locate?calibrate=1&m={{ $model->id }}" target="_top">{!! ic('sliders-horizontal', '', 16) !!}<span>{{ __('Настроить привязку') }}</span></a>@endif
    @if ($embed)<a href="#" data-act="full" target="_blank">{!! ic('external-link', '', 16) !!}<span>{{ __('Во весь экран') }}</span></a>@endif
  </div>
  @if ($calibrate)
  <aside class="lc-side" id="lcSide">
    <h3>{!! ic('layers') !!}{{ __('Привязка корпуса к 3D-модели') }}</h3>
    @if ($multi)
    <label class="field"><span>{{ __('Корпус') }}</span><select id="cBlock">@foreach ($blocks as $bid => $b)<option value="{{ $bid }}">{{ $b['name'] ?: __('Корпус') }} · {{ count($b['entr']) }} {{ __('подъезд') }}@if (isset($cal[$bid])) ✓@endif</option>@endforeach</select></label>
    @endif
    <div class="seg lc-tabs" id="cTabs">
      <button type="button" data-t="frame" class="on"><i>1</i>{{ __('Рамка') }}</button>
      <button type="button" data-t="units"><i>2</i>{{ __('Квартиры') }}</button>
      <button type="button" data-t="inner"><i>3</i>{{ __('Внутри') }}</button>
    </div>
    <p class="lc-stepnote" id="cStepNote">{{ __('Шаг 1. Рамка обнимает дом от земли до крыши — по ней считаются этажи и подъезды. ИИ ставит её сам; проверьте и поправьте.') }}</p>
    <div id="tabUnits" class="lc-tab" hidden>
      <div class="lc-ucard" id="uCard" hidden>
        <div class="lc-uhead" id="uHead"></div>
        <div class="lc-place" id="uPlace"></div>
        @if ($canUnits)
        <div class="row2">
          <label class="field"><span>{{ __('Комнат') }}</span><div class="lc-step" id="uRoomsStep"><button type="button" data-d="-1">−</button><input type="number" min="1" max="9" step="1" id="uRooms"><button type="button" data-d="1">+</button></div></label>
          <label class="field"><span>{{ __('Площадь, м²') }}</span><input type="number" min="5" max="5000" step="0.1" id="uArea"></label>
        </div>
        <button type="button" class="btn btn-s" id="uSave" hidden>{!! ic('check', '', 15) !!}{{ __('Сохранить квартиру') }}</button>
        @endif
        <label class="lc-chk"><input type="checkbox" id="uAll" checked><span>{{ __('На всех этажах, где столько же квартир, — на тех же окнах') }}</span></label>
        <div class="lc-inmini" id="uInMini"></div>
      </div>
      <div class="lc-sec">
        <span class="lc-lab">{{ __('Окна на фасаде') }}</span>
        <div class="lc-wrows">
          <div class="lc-wrow"><span>{{ __('Лицевая сторона') }}</span><div class="lc-step sm" data-w="f"><button type="button" data-d="-1">−</button><input type="number" min="1" max="120" id="wF"><button type="button" data-d="1">+</button></div></div>
          <div class="lc-wrow"><span>{{ __('Задняя сторона') }}</span><div class="lc-step sm" data-w="b"><button type="button" data-d="-1">−</button><input type="number" min="1" max="120" id="wB"><button type="button" data-d="1">+</button></div></div>
          <div class="lc-wrow"><span>{{ __('Торцы') }}</span><div class="lc-step sm" data-w="s"><button type="button" data-d="-1">−</button><input type="number" min="1" max="120" id="wS"><button type="button" data-d="1">+</button></div></div>
        </div>
        <small class="lc-ptext">{{ __('Окон на фасаде не столько? Поправьте число — сетка окон встанет поровну.') }}</small>
        <button type="button" class="btn btn-s btn-ghost" id="uRealloc">{!! ic('refresh-cw', '', 15) !!}{{ __('Заново по окнам: окон — по числу комнат + 1') }}</button>
      </div>
      <button type="button" class="btn btn-s" id="fac3d">{!! ic('box', '', 15) !!}{{ __('Проверить в 3D') }}</button>
    </div>
    <div id="tabInner" class="lc-tab" hidden>
      <p class="lc-hint">{{ __('Интерьер загружается для планировки: его увидят у всех квартир этого типа — в продажах, на сайте и в кабинете покупателя (кнопка «Внутри»).') }}</p>
      <div id="iList" class="lc-ilayouts"></div>
      <div class="lc-prog" id="uProg" hidden><i></i></div><small class="lc-ptext" id="uProgT" hidden></small>
    </div>
    <div id="tabFrame" class="lc-tab">
    <div class="lc-ai">
      <div class="lc-ai-h">{!! ic('sparkles', '', 17) !!}{{ __('Автопривязка ИИ') }}</div>
      <p id="cAiText">{{ __('ИИ изучит модель: где дом, сколько в нём этажей, где входы в подъезды — и сам расставит квартиры. Вам останется проверить номера на фасаде.') }}</p>
      <ul class="lc-warn" id="cAiWarn" hidden></ul>
      <button type="button" class="btn btn-p btn-s" id="cAuto">{!! ic('sparkles', '', 15) !!}<span>{{ __('Расставить квартиры с ИИ') }}</span></button>
    </div>
    <div class="lc-sec">
      <span class="lc-lab">{{ __('Ракурс') }}</span>
      <div class="seg" id="cView"><button type="button" data-v="3d" class="on">3D</button><button type="button" data-v="front">{{ __('Спереди') }}</button><button type="button" data-v="back">{{ __('Сзади') }}</button><button type="button" data-v="side">{{ __('Торец') }}</button><button type="button" data-v="top">{{ __('Сверху') }}</button></div>
    </div>
    <div class="lc-sec">
      <span class="lc-lab">{{ __('Поправить вручную') }}</span>
      <p class="lc-hint">{{ __('Тяните точки на рамке') }}: <i style="background:var(--accent)"></i>{{ __('стороны рамки') }}, <i style="background:var(--accent-2)"></i>{{ __('границы подъездов') }}, <i style="background:#f59e0b"></i>{{ __('верх 1-го этажа') }}. {{ __('Двойной щелчок по дому — перенести рамку туда.') }}</p>
      <div class="seg" id="cMode"><button type="button" data-m="translate" class="on">{{ __('Двигать') }}</button><button type="button" data-m="rotate">{{ __('Поворот') }}</button></div>
      <div class="row2">
        <button type="button" class="btn btn-s" id="cSnap" title="{{ __('Рамка встанет по стенам дома там, где она сейчас: без крыши и земли') }}">{!! ic('scan-line', '', 15) !!}{{ __('Прижать к дому') }}</button>
        <button type="button" class="btn btn-s" id="cUndo" disabled>{!! ic('undo-2', '', 15) !!}{{ __('Отменить') }}</button>
      </div>
    </div>
    <div class="lc-sec">
      <div class="row2">
        <label class="field"><span>{{ __('Этажей в рамке') }}</span><div class="lc-step" id="cFStep"><button type="button" data-d="-1" aria-label="−">−</button><input type="number" step="1" min="1" max="200" id="cF"><button type="button" data-d="1" aria-label="+">+</button></div></label>
        <label class="field"><span>{{ __('Высота 1-го этажа, м') }}</span><input type="number" step="0.1" min="0" max="30" id="cG" placeholder="{{ __('как остальные') }}"></label>
      </div>
      <div class="row2">
        <label class="field"><span>{{ __('Квартиры на этаже') }}</span><select id="cRows"><option value="2">{{ __('с двух сторон') }}</option><option value="3">{{ __('сквозные: окна спереди и сзади') }}</option><option value="1">{{ __('на одну сторону') }}</option></select></label>
        <label class="field"><span>{{ __('Задняя сторона') }}</span><select id="cBack"><option value="rev">{{ __('нумерация по кругу') }}</option><option value="fwd">{{ __('как лицевая') }}</option></select></label>
      </div>
      <div class="row2">
        <button type="button" class="btn btn-s" id="cFlipX">{!! ic('arrow-left-right', '', 15) !!}{{ __('Подъезды наоборот') }}</button>
        <button type="button" class="btn btn-s" id="cFlipZ">{!! ic('refresh-cw', '', 15) !!}{{ __('Сменить фасад') }}</button>
      </div>
      @if (collect($blocks)->contains(fn ($b) => count($b['entr']) > 1))
      <button type="button" class="btn btn-s btn-ghost" id="cEven">{!! ic('layout-grid', '', 15) !!}{{ __('Подъезды поровну') }}</button>
      @endif
      <details class="lc-more"><summary>{{ __('Точные размеры') }}</summary>
        <div class="row">
          <label class="field"><span>{{ __('Длина, м') }}</span><input type="number" step="0.1" min="0.1" id="cL"></label>
          <label class="field"><span>{{ __('Высота, м') }}</span><input type="number" step="0.1" min="0.1" id="cH"></label>
          <label class="field"><span>{{ __('Глубина, м') }}</span><input type="number" step="0.1" min="0.1" id="cD"></label>
        </div>
        <label class="field" style="margin-top:8px"><span>{{ __('Поворот, °') }}</span><input type="number" step="1" id="cR"></label>
      </details>
    </div>
    {{-- загрузили не ту модель — заменить или удалить прямо отсюда --}}
    <div class="lc-sec lc-model">
      <span class="lc-lab">{{ __('3D-модель здания') }}</span>
      @if (($models ?? collect())->count() > 1)
      <select id="cModel" aria-label="{{ __('3D-модель здания') }}">@foreach ($models as $mm)<option value="{{ $mm->id }}"@selected($mm->id === $model->id)>{{ $mm->title ?: __('Модель').' #'.$mm->id }}@if ($mm->is_main) · {{ __('главная') }}@endif</option>@endforeach</select>
      @else
      <b class="lc-model-n">{{ $model->title ?: __('Модель').' #'.$model->id }}</b>
      @endif
      <div class="row2">
        <a class="btn btn-s" href="/company/objects/{{ $pid }}?tab=media&kind=exterior#upload-card" target="_top">{!! ic('upload', '', 15) !!}{{ __('Загрузить другую') }}</a>
        <button type="button" class="btn btn-s btn-d" id="cModelDel">{!! ic('trash-2', '', 15) !!}{{ __('Удалить модель') }}</button>
      </div>
    </div>
    </div>
    <div class="lc-foot">
      <button type="button" class="btn btn-p" id="cSave">{!! ic('check') !!}{{ __('Сохранить привязку') }}</button>
      <a class="btn" href="/company/objects/{{ $pid }}/locate?m={{ $model->id }}">{!! ic('eye', '', 16) !!}{{ __('Проверить на квартире') }}</a>
    </div>
  </aside>
  @endif
  {{-- шаг 2: плоский фасад — квартиры на окнах щелчками, без 3D-рамок и стрелок --}}
  <div class="lc-fac" id="lcFac" hidden>
    <div class="lc-fac-bar">
      <div class="seg" id="facSide"><button type="button" data-f="1" class="on">{{ __('Лицевая сторона') }}</button><button type="button" data-f="-1">{{ __('Задняя сторона') }}</button><button type="button" data-f="-2">{{ __('Левый торец') }}</button><button type="button" data-f="2">{{ __('Правый торец') }}</button></div>
      <div class="lc-fac-fl"><button type="button" id="facFDown" title="{{ __('Этаж ниже') }}">{!! ic('chevron-down', '', 16) !!}</button><b id="facFloor"></b><button type="button" id="facFUp" title="{{ __('Этаж выше') }}">{!! ic('chevron-down', 'up', 16) !!}</button></div>
    </div>
    <div class="lc-fac-apts" id="facApts"></div>
    <div class="lc-fac-stage" id="facStage">
      <div class="lc-fac-pic" id="facPic"><img id="facImg" alt=""><div class="lc-fac-grid" id="facGrid"></div></div>
      <div class="lc-fac-load" id="facLoad" hidden><i></i></div>
    </div>
  </div>
  <button type="button" class="btn btn-p lc-fac-back" id="facBack" hidden>{!! ic('arrow-left', '', 15) !!}{{ __('Вернуться к фасаду') }}</button>
  <div class="lc-ov" id="lcOv" hidden><div class="lc-ov-in"><button type="button" class="lc-ov-x" id="lcOvX" aria-label="{{ __('Закрыть') }}">{!! ic('x', '', 18) !!}</button><div id="lcOvB" style="height:100%"></div></div></div>
@endif

@if ($model)
<script type="module">
import { start } from '{{ asset('assets/locate3d.js') }}?v={{ @filemtime(public_path('assets/locate3d.js')) }}';
import { innerManager, layKey } from '{{ asset('assets/inner-media.js') }}?v={{ @filemtime(public_path('assets/inner-media.js')) }}';
const T = @json($T), DATA = @json($data), CAL = @json((object) $cal), UNIT = @json($unit), CALIB = @json((bool) $calibrate);
const CAN = @json((bool) $canEdit), AUTO = @json((bool) $auto), AI_URL = @json($aiUrl), SAVE_URL = @json($saveUrl);
const SETUP = @json($canEdit ? '/company/objects/'.$pid.'/locate?calibrate=1&m='.$model->id : null);
// интерьеры квартир («Внутри»): файлы, адрес страницы 3D и файла; в настройке — загрузка к выбранной квартире и её комнаты
const INNER = @json($inner), URLS = @json($innerT), CAN_UNITS = @json((bool) $canUnits), HAS_INV = @json((bool) ($hasInv ?? true));
const PATCH_URL = @json(url('/company/media/{id}/patch')), DEL_URL = @json(url('/company/media/{id}'));
const UP_URL = @json($upUrl ?? null), UNIT_URL = @json($unitUrl ?? null), CVT = @json($cvt ?? []), MC_URL = @json($mcUrl ?? null);
const ICON = {!! json_encode(['interior' => ic('box', '', 22), 'pano' => ic('compass', '', 22), 'photo' => ic('image', '', 22), 'plan' => ic('ruler', '', 22), 'pin' => ic('map-pin', '', 15), 'eye' => ic('eye', '', 15), 'up' => ic('upload', '', 15), 'box' => ic('box', '', 16), 'compass' => ic('compass', '', 16), 'image' => ic('image', '', 16), 'ruler' => ic('ruler', '', 16), 'edit' => ic('pencil', '', 13), 'del' => ic('trash-2', '', 13)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const CSRF = document.querySelector('meta[name=csrf-token]').content;
// подписи помещений на языке интерфейса
const labelUnit = (u) => {
  const B = DATA.blocks[u.b] || { name: '', entr: [] }, en = B.entr[u.e] || (u.e + 1);
  u.label = u.r ? `${T.kv} ${u.num}` : `${T.k} ${u.num}`;
  u.sub = [(B.name && Object.keys(DATA.blocks).length > 1 ? B.name + ' · ' : '') + `${en}-${T.en}`, `${u.f}-${T.fl}`, u.r ? `${u.r}${T.rooms}` : '', u.a ? `${u.a} ${T.m2}` : ''].filter(Boolean).join(' · ');
};
DATA.units.forEach(labelUnit);
// интерьер квартиры — тем же правилом, что ObjectMedia::forUnit: своей планировки, иначе по числу комнат, иначе общий
const innerOf = (u) => { const own = u.t ? INNER.filter((m) => m.l === u.t) : []; return own.length ? own : INNER.filter((m) => !m.l && (m.r == null || +m.r === +u.r)); };
const fileUrl = (m) => URLS.file.replace('{id}', m.id);
let L, aiNote = null, current = null, tab = 'frame';
try {
  L = await start(document.getElementById('lc'), {
    src: @json($src), data: DATA, cal: CAL, calibrate: CALIB, T,
    autoSrc: '{{ asset('assets/locate-auto.js') }}?v={{ @filemtime(public_path('assets/locate-auto.js')) }}',
    onProgress: (k) => { const e = $('#lcPct'); if (e) e.textContent = Math.round(k * 100) + '%'; },
    onShow: (u, ok, isAuto) => {
      $('#lcU').innerHTML = `<strong>${u.label}</strong> · ${u.sub}` + (u.s && T.st[u.s] ? ` · ${T.st[u.s]}` : '');
      note(ok, isAuto);
      current = u;
      const ib = $('#lcBar [data-act=inner]'); if (ib) ib.hidden = CALIB || !URLS.view || !innerOf(u).length;
    },
    // разметка квартир: щёлкнули квартиру на фасаде — её карточка; отметили окна — сколько их на каждой стороне
    onSelect: (u) => { FAC.floor = u.f; card(u); facDraw(); },
    onAssign: (u) => { if (u) { card(u); facDraw(); } },
    onChange: (c) => fill(c),
    onUndo: (n) => { const b = $('#cUndo'); if (b) b.disabled = !n; },
    // весь дом: наведение — номер и статус, клик — квартира (в программе откроется её карточка)
    onHover: (u) => { if (u) $('#lcU').innerHTML = `<strong>${u.label}</strong> · ${u.sub}` + (u.s && T.st[u.s] ? ` · ${T.st[u.s]}` : ''); },
    onPick: (u) => { if (!u) return; L.show(u.id); if (window.parent !== window) window.parent.postMessage({ type: 'oftob-locate-pick', unit: u.id }, location.origin); },
  });
} catch (e) {
  $('#lcLoad').innerHTML = `<div>${T.fail}</div>`; throw e;
}
$('#lcLoad').remove();
window.oftobLocate = L;
// в карточке продаж: сообщаем, что модель загружена — можно переключать квартиры
if (window.parent !== window) window.parent.postMessage({ type: 'oftob-locate-ready' }, location.origin);
const first = UNIT && L.units[UNIT] ? UNIT : null;
document.querySelectorAll('#lcBar [data-act]').forEach((b) => b.addEventListener('click', (e) => {
  const a = b.dataset.act;
  if (a === 'unit') L.focusSelected();
  if (a === 'house') { if (CALIB) L.overview(); else { L.statusView(); $('#lcU').innerHTML = T.pickHint + legend(); $('#lcNote').hidden = true; b.parentNode.querySelector('[data-act=inner]').hidden = true; } }
  if (a === 'inner' && current) openInner(current);
  if (a === 'full') { e.preventDefault(); const u = new URL(location.href); u.searchParams.delete('embed'); window.open(u, '_blank'); }
}));
/* ---------- «Внутри квартиры»: 3D-интерьер и панорамы — страница 3D только этой квартиры; рендер и план — картинкой ---------- */
// квартира изнутри: просмотр открывает 3D-интерьер, комнату (её 360° и фото) или чертёж — с переходами между ними
function openInner(u, m, room) {
  const list = innerOf(u);
  if (!m && room == null) m = list.find((x) => x.k === 'interior' || x.k === 'pano') || list[0];
  if (!m && room == null) return;
  const box = $('#lcOvB');
  if (URLS.view) {
    const url = URLS.view.replace('{u}', encodeURIComponent(u.id)).replace('{t}', encodeURIComponent(u.t || '')).replace('{r}', u.r || 0) + (m ? '&m=' + m.id : '&room=' + encodeURIComponent(room));
    box.innerHTML = `<iframe src="${esc(url)}" allow="fullscreen; xr-spatial-tracking" title="3D"></iframe>`;
  } else if (m) box.innerHTML = m.ext === 'pdf' ? `<iframe src="${esc(fileUrl(m))}" title="PDF"></iframe>` : `<img src="${esc(fileUrl(m))}" alt="${esc(m.n)}">`;
  $('#lcOv').hidden = false;
  L.pause(true);
}
function closeInner() { $('#lcOv').hidden = true; $('#lcOvB').innerHTML = ''; L.pause(false); }
$('#lcOvX').addEventListener('click', closeInner);
$('#lcOv').addEventListener('click', (e) => { if (e.target.id === 'lcOv') closeInner(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#lcOv').hidden) closeInner(); });
// продажи: одна загрузка модели, квартиры переключаются сообщением из карточки
let shown = first;
window.addEventListener('message', (e) => {
  if (e.origin !== location.origin || !e.data) return;
  if (e.data.type === 'oftob-locate-pause') { L.pause(!!e.data.on); return; }
  if (e.data.type !== 'oftob-locate') return;
  L.pause(false);
  if (L.units[e.data.unit]) { shown = e.data.unit; L.show(e.data.unit); const u = new URL(location.href); u.searchParams.set('u', e.data.unit); history.replaceState(null, '', u); }
});

/* ---------- автопривязка: форма модели + ИИ смотрит на фасады ---------- */
// ИИ спрашивает только тот, кто может править привязку; ответ — этажи, двери подъездов, лицевая сторона
const askAi = AI_URL ? async (image, entr) => {
  const r = await fetch(AI_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify({ image, entr }) }).catch(() => null);
  if (!r || !r.ok) { aiNote = 'fail'; return null; }
  const j = await r.json().catch(() => ({}));
  aiNote = j.note || null;
  return j.ok ? j.a : null;
} : null;
async function autoFit(ids, withAi) {
  try { return await L.autoFit({ only: ids, ai: withAi ? askAi : null }); } catch (e) { console.error(e); return null; }
}
async function save(bid, isAuto) {
  const c = L.cal(bid);
  if (!isAuto) L.confirm(bid);
  const body = { block: bid, c: c.c, s: c.s, r: c.r, floors: c.floors, gf: c.gf || 0, rows: c.rows, back: c.back, flipX: !!c.flipX, flipZ: !!c.flipZ, ex: c.ex || [],
    win: c.win || undefined, slots: c.slots || undefined, ux: c.ux || undefined };
  if (isAuto && c.auto) body.auto = { by: c.auto.by, mf: c.auto.mf ?? undefined, doors: c.auto.doors ?? undefined, warn: c.auto.warn || [] };
  const r = await fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(body) });
  if (!r.ok) throw new Error(r.status);
  const j = await r.json();
  if (!j.skipped) L.markSaved(bid);
  return j;
}
function note(ok, isAuto) {
  const n = $('#lcNote');
  if (!n) return;
  n.classList.toggle('ai', !!(ok && isAuto));
  if (CALIB || (ok && !(isAuto && CAN))) { n.hidden = true; return; }
  n.hidden = false;
  if (ok) n.innerHTML = `${T.aiAuto} <a href="${SETUP}" target="_top">${T.check}</a>`;
  else if (CAN) n.innerHTML = `${T.approx} <a href="${SETUP}" target="_top">${T.setup}</a>`;
  else n.textContent = T.approx;
}
const busy = (on, text) => { const b = $('#lcBusy'); if (!b) return; b.hidden = !on; if (text) $('#lcBusyT').textContent = text; };
/** Что сказала автопривязка — в панель настройки */
function report(bid) {
  const t = $('#cAiText'), w = $('#cAiWarn');
  if (!t) return;
  const a = L.cal(bid).auto;
  if (!a) { w.hidden = true; return; }
  t.textContent = a.by === 'ai' ? T.aiDone : aiNote === 'limit' ? T.aiLimit : aiNote === 'off' ? T.aiOff : aiNote === 'fail' ? T.aiFail : T.geoDone;
  const B = DATA.blocks[bid], items = [];
  (a.warn || []).forEach((k) => {
    if (k === 'floors' && a.mf) items.push(T.wFloors.replace(':m', a.mf).replace(':n', B.floors));
    if (k === 'doors') items.push(T.wDoors.replace(':d', a.doors ?? 0).replace(':e', B.entr.length));
    if (k === 'notBuilding') items.push(T.wNotBuilding);
    if (k === 'blocks') items.push(T.wBlocks);
  });
  w.innerHTML = items.map((s) => `<li>${s}</li>`).join('');
  w.hidden = !items.length;
}

// цвета квартир — те же, что на 3D-модели (по номеру квартиры на этаже); состояние плоского фасада и интерьеров — до шагов настройки
const COLORS = ['#6c4cf1', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#ec4899', '#14b8a6', '#8b5cf6'];
const FAC = { face: 1, shot: null, floor: null, paint: null };
let IM = null;
const unbound = L.blocks.filter((b) => !L.isCalibrated(b));
if (CALIB) {
  const sel = $('#cBlock');
  const startBlock = first ? L.units[first].b : (sel ? sel.value : L.blocks[0]);
  if (sel) sel.value = startBlock;
  fill(L.calibrate(startBlock)); report(startBlock);
  sel && sel.addEventListener('change', () => { fill(L.calibrate(sel.value)); report(sel.value); if (tab === 'units') { L.mark(true); FAC.floor = null; $('#uCard').hidden = true; facOpen(); } });
  const mark = (bid) => { const o = sel && [...sel.options].find((x) => x.value === bid); if (o && !o.textContent.endsWith('✓')) o.textContent += ' ✓'; };
  // сразу после загрузки модели (auto=1) или пока корпус не привязан — ИИ расставляет сам и сохраняет; человек потом проверяет
  const runAuto = async (ids) => {
    busy(true, T.aiBusy);
    const rep = await autoFit(ids, true);
    if (!rep || !Object.keys(rep).length) { busy(false); $('#cAiText').textContent = T.fail2; return; }
    for (const b of Object.keys(rep)) { try { const j = await save(b, true); if (!j.skipped) mark(b); } catch (e) { /* сохранит человек кнопкой */ } }
    const bid = L.block(); fill(L.cal(bid)); report(bid);
    busy(false);
  };
  if (AUTO || unbound.length) await runAuto(AUTO ? L.blocks : unbound);
  $('#cAuto').addEventListener('click', () => runAuto([L.block()]));
  document.querySelectorAll('#cView button').forEach((b) => b.addEventListener('click', () => { document.querySelectorAll('#cView button').forEach((x) => x.classList.toggle('on', x === b)); L.view(b.dataset.v); }));
  document.querySelectorAll('#cMode button').forEach((b) => b.addEventListener('click', () => { document.querySelectorAll('#cMode button').forEach((x) => x.classList.toggle('on', x === b)); L.mode(b.dataset.m); }));
  $('#cSnap').addEventListener('click', async () => { busy(true, '…'); try { fill(await L.snap()); } finally { busy(false); } });
  $('#cUndo').addEventListener('click', () => fill(L.undo()));
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !/INPUT|SELECT|TEXTAREA/.test(e.target.tagName)) { e.preventDefault(); fill(L.undo()); } });
  const num = (id) => parseFloat($(id).value);
  ['#cL', '#cH', '#cD'].forEach((id) => $(id).addEventListener('change', () => { const c = L.get(); fill(L.set({ s: [num('#cL') || c.s[0], num('#cH') || c.s[1], num('#cD') || c.s[2]] })); }));
  $('#cR').addEventListener('change', () => fill(L.set({ r: (num('#cR') || 0) * Math.PI / 180 })));
  const setFloors = (n) => fill(L.set({ floors: Math.max(1, Math.min(200, n || 1)) }));
  $('#cF').addEventListener('change', () => setFloors(parseInt($('#cF').value)));
  document.querySelectorAll('#cFStep button').forEach((b) => b.addEventListener('click', () => setFloors((parseInt($('#cF').value) || 1) + +b.dataset.d)));
  $('#cG').addEventListener('change', () => fill(L.set({ gf: Math.max(0, parseFloat($('#cG').value) || 0) })));
  $('#cRows').addEventListener('change', () => fill(L.set({ rows: +$('#cRows').value })));
  $('#cBack').addEventListener('change', () => fill(L.set({ back: $('#cBack').value })));
  $('#cFlipX').addEventListener('click', () => fill(L.set({ flipX: !L.get().flipX })));
  $('#cFlipZ').addEventListener('click', () => fill(L.set({ flipZ: !L.get().flipZ })));
  $('#cEven')?.addEventListener('click', () => fill(L.even()));
  $('#cSave').addEventListener('click', async () => {
    const btn = $('#cSave'); btn.disabled = true;
    try {
      await save(L.block(), false);
      if (window.ui && ui.toast) ui.toast(T.saved); else btn.lastChild.textContent = ' ' + T.saved;
      mark(L.block()); report(L.block());
      $('#cAiWarn').hidden = true;
    } catch (e) { alert(T.err); } finally { btn.disabled = false; }
  });
  // модель здания: переключиться на другую загруженную; удалить ошибочную (вместе с её привязкой)
  $('#cModel')?.addEventListener('change', (e) => { const to = new URL(location.href); to.searchParams.set('m', e.target.value); to.searchParams.delete('auto'); to.searchParams.delete('mk'); location.href = to; });
  $('#cModelDel').addEventListener('click', async () => {
    if (!confirm(T.delModelQ)) return;
    const btn = $('#cModelDel'); btn.disabled = true;
    const r = await fetch(DEL_URL.replace('{id}', @json($model->id)), { method: 'DELETE', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF } }).catch(() => null);
    if (r && r.ok) { (window.top || window).location.href = @json(url('/company/objects/'.$pid.'?tab=media')); return; }
    const j = r ? await r.json().catch(() => ({})) : {};
    alert(j.error || j.message || T.err); btn.disabled = false;
  });

  /* ---------- три шага: 1 — рамка, 2 — квартиры на окнах, 3 — интерьер планировок ---------- */
  const setTab = async (t) => {
    tab = t;
    document.querySelectorAll('#cTabs button').forEach((x) => x.classList.toggle('on', x.dataset.t === t));
    $('#tabFrame').hidden = t !== 'frame'; $('#tabUnits').hidden = t !== 'units'; $('#tabInner').hidden = t !== 'inner';
    $('#cStepNote').textContent = T.steps[t];
    $('#lcFac').hidden = true; $('#facBack').hidden = true;
    L.mark(t !== 'frame');   // на шагах 2 и 3 рамку не двигаем — стрелок нет
    if (t === 'frame') { fill(L.get()); return; }
    if (t === 'inner') { IM.render(); return; }
    // привязку сделали раньше, окон в ней нет — находим их на фасаде, чтобы квартиры встали по окнам
    if (!L.get().win) { busy(true, T.findWin); try { await L.findWindows(); } catch (e) { console.error(e); } finally { busy(false); } }
    winFill();
    await facOpen();
  };
  document.querySelectorAll('#cTabs button').forEach((b) => b.addEventListener('click', () => setTab(b.dataset.t)));
  // плоский фасад: стороны, этажи, квартиры этажа, щелчки по окнам
  document.querySelectorAll('#facSide button').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('#facSide button').forEach((x) => x.classList.toggle('on', x === b));
    FAC.face = +b.dataset.f; facShot();
  }));
  const fstep = (d) => { const fs = floorsList(), k = fs.indexOf(FAC.floor), f = fs[Math.max(0, Math.min(fs.length - 1, k + d))]; if (f != null) facFloor(f); };
  $('#facFDown').addEventListener('click', () => fstep(-1));
  $('#facFUp').addEventListener('click', () => fstep(1));
  $('#facApts').addEventListener('click', (e) => { const b = e.target.closest('[data-u]'); if (b) pick(b.dataset.u); });
  $('#facGrid').addEventListener('pointerdown', facDown);
  window.addEventListener('pointermove', facMove);
  window.addEventListener('pointerup', () => { if (FAC.paint) { FAC.paint = null; const s = L.selected(); if (s) card(s); facDraw(); } });
  new ResizeObserver(() => facFit()).observe($('#facStage'));
  // проверить в 3D: дом с отмеченными квартирами; вернуться — к плоскому фасаду
  $('#fac3d').addEventListener('click', () => { $('#lcFac').hidden = true; $('#facBack').hidden = false; L.markMode('select'); L.markFloor(FAC.floor); L.view('3d'); });
  $('#facBack').addEventListener('click', () => { $('#lcFac').hidden = false; $('#facBack').hidden = true; facDraw(); });
  $('#uAll').addEventListener('change', () => L.markAll($('#uAll').checked));
  // окна на фасаде: ИИ их нашёл; если ошибся — задайте число, окна встанут поровну, квартиры — заново по ним
  const setWin = (side, n) => { L.setWin(side, n || 1); winFill(); const u = L.selected(); if (u) card(u); facDraw(); };
  document.querySelectorAll('.lc-step[data-w]').forEach((s) => {
    const side = s.dataset.w, inp = s.querySelector('input');
    s.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => setWin(side, (parseInt(inp.value) || 1) + +b.dataset.d)));
    inp.addEventListener('change', () => setWin(side, parseInt(inp.value)));
  });
  $('#uRealloc').addEventListener('click', () => { if (!confirm(T.realloc)) return; L.realloc(); const u = L.selected(); if (u) card(u); facDraw(); });
  // комнаты и площадь — прямо отсюда (у квартиры со сделкой не меняются)
  if (CAN_UNITS) {
    const dirtyU = () => { $('#uSave').hidden = false; };
    $('#uRooms').addEventListener('input', dirtyU); $('#uArea').addEventListener('input', dirtyU);
    document.querySelectorAll('#uRoomsStep button').forEach((b) => b.addEventListener('click', () => { const i = $('#uRooms'); i.value = Math.max(1, Math.min(9, (parseInt(i.value) || 1) + +b.dataset.d)); dirtyU(); }));
    $('#uSave').addEventListener('click', async () => {
      const u = L.selected(); if (!u) return;
      const rooms = parseInt($('#uRooms').value), area = parseFloat($('#uArea').value);
      if (!(rooms >= 1)) return;
      if (!HAS_INV && !confirm(T.convert)) return;
      const btn = $('#uSave'); btn.disabled = true;
      try {
        if (!HAS_INV) await save(L.block(), false);   // корпус сменит id — привязку сохраняем до перевода
        const r = await fetch(UNIT_URL.replace('{id}', encodeURIComponent(u.id)), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify({ rooms, area: area > 0 ? area : undefined }) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { alert(j.error || j.message || T.err); return; }
        if (j.converted) { const to = new URL(location.href); to.searchParams.set('mk', '1'); to.searchParams.set('u', u.id); to.searchParams.delete('auto'); location.href = to; return; }
        if (j.unit) { L.updateUnit(u.id, { r: j.unit.r, a: j.unit.a, t: j.unit.t || undefined }); labelUnit(u); }
        card(u); facDraw();
        if (window.ui && ui.toast) ui.toast(T.unitSaved);
      } finally { btn.disabled = false; }
    });
  }
  // интерьер: в карточке квартиры — что уже есть и «Смотреть»; загрузка — на шаге 3 по планировкам
  $('#uInMini').addEventListener('click', (e) => {
    const a = e.target.closest('[data-a]'), u = L.selected(); if (!a || !u) return;
    if (a.dataset.a === 'view') openInner(u);
    if (a.dataset.a === 'up') { IM.setCur(layKey(u)); setTab('inner'); }
  });
  IM = innerManager($('#iList'), { T, ICON, inner: INNER, units: DATA.units, csrf: CSRF, cvt: CVT, mcUrl: MC_URL, canEdit: !!UP_URL, prog,
    urls: { up: UP_URL, file: URLS.file, patch: PATCH_URL, del: DEL_URL },
    onView: (u, m, room) => openInner(u, m, room),
    onChange: () => { const s = L.selected(); if (s && !$('#uCard').hidden) card(s); } });
  const params = new URLSearchParams(location.search);
  if (params.get('mk')) { if (first) { L.select(first); FAC.floor = L.units[first].f; } await setTab('units'); if (first) card(L.units[first]); }
} else {
  if (first) L.show(first); else { L.statusView(); $('#lcU').innerHTML = T.pickHint + legend(); }
  // корпус не привязан: руководитель/администратор объектов — ИИ расставляет и сохраняет; остальным — по форме модели, без сохранения
  if (unbound.length) {
    const n = $('#lcNote');
    if (CAN && n) { n.hidden = false; n.classList.add('ai'); n.textContent = T.aiRun; }
    const rep = await autoFit(unbound, CAN);
    if (rep && CAN) for (const bid of Object.keys(rep)) { try { await save(bid, true); } catch (e) { /* не страшно: в следующий раз */ } }
    if (shown) L.show(shown); else { L.statusView(); if (n) n.hidden = true; }
  }
}
/* ---------- шаг 2: плоский фасад — квартиры на окнах ---------- */
function unitsList() {
  return DATA.units.filter((u) => u.b === L.block()).sort((a, b) => a.e - b.e || a.f - b.f || a.i - b.i);
}
const floorsList = () => [...new Set(unitsList().map((u) => u.f))].sort((a, b) => a - b);
const colorOf = (u) => COLORS[u.i % COLORS.length];
async function facOpen() {
  $('#lcFac').hidden = false; $('#facBack').hidden = true;
  const sel = L.selected();
  if (FAC.floor == null) { const u = sel || unitsList().find((x) => x.r > 0) || unitsList()[0]; FAC.floor = u ? u.f : 1; }
  if (!sel || sel.f !== FAC.floor) { const u = unitsList().find((x) => x.f === FAC.floor); if (u) L.select(u.id); }
  L.markFloor(FAC.floor);
  const s = L.selected(); if (s) card(s);
  await facShot();
}
/** Снимок стороны дома — заново при смене стороны (рамка на шаге 2 не двигается) */
async function facShot() {
  $('#facLoad').hidden = false;
  await new Promise((r) => requestAnimationFrame(() => r()));
  try { FAC.shot = L.facade(FAC.face); } catch (e) { console.error(e); }
  if (FAC.shot) await new Promise((r) => { const img = $('#facImg'); img.onload = r; img.onerror = r; img.src = FAC.shot.url; });
  $('#facLoad').hidden = true;
  facFit(); facDraw();
}
function facFit() {
  const st = $('#facStage'), s = FAC.shot;
  if (!s || !st || $('#lcFac').hidden) return;
  const k = Math.min((st.clientWidth - 28) / s.w, (st.clientHeight - 28) / s.h);
  $('#facPic').style.width = Math.max(50, Math.floor(s.w * k)) + 'px'; $('#facPic').style.height = Math.max(50, Math.floor(s.h * k)) + 'px';
}
function facFloor(f) {
  FAC.floor = f;
  L.markFloor(f);
  const s = L.selected();
  if (!s || s.f !== f) { const u = unitsList().find((x) => x.f === f); if (u) { L.select(u.id); card(u); } }
  facDraw();
}
function pick(id) { const u = L.select(id); if (!u) return; FAC.floor = u.f; card(u); facDraw(); }
/** Сетка окон поверх снимка: активный этаж — окна цветом своих квартир с номером, остальные этажи затенены (щелчок — перейти на этаж) */
function facDraw() {
  const s = FAC.shot;
  if (!s || $('#lcFac').hidden) return;
  const G = L.facadeGrid(FAC.face), m = s.m, k = 1 + 2 * m, own = L.facadeOwners(FAC.face, FAC.floor), sel = L.selected();
  const pct = (v) => (v * 100).toFixed(3) + '%';
  const X = (a0, a1) => [(s.dir > 0 ? m + a0 : m + 1 - a1) / k, (a1 - a0) / k];
  const row = G.rows.find((r) => r.floors.includes(FAC.floor)) || G.rows[0];
  let html = '';
  G.rows.forEach((r) => {
    if (r === row) return;
    html += `<div class="fv" data-fl="${r.floors[0] ?? ''}" style="top:${pct((m + 1 - r.y1) / k)};height:${pct((r.y1 - r.y0) / k)}" title="${r.floors.map((f) => f + '-' + T.fl).join(', ')}"></div>`;
  });
  const top = (m + 1 - row.y1) / k, h = (row.y1 - row.y0) / k;
  html += `<div class="fa" style="top:${pct(top)};height:${pct(h)};left:${pct(m / k)};width:${pct(1 / k)}"></div>`;
  for (let i = 0; i < G.edges.length - 1; i++) {
    const [l, w] = X(G.edges[i], G.edges[i + 1]), u = own[i] && L.units[own[i]], on = !!(u && sel && u.id === sel.id);
    html += `<div class="fc${u ? '' : ' free'}${on ? ' on' : ''}" data-k="${i}" style="left:${pct(l)};width:${pct(w)};top:${pct(top)};height:${pct(h)};${u ? `background:${colorOf(u)}${on ? 'e6' : '9c'}` : ''}" title="${u ? esc(u.label) : esc(T.freeWin)}">${u ? esc(u.num) : ''}</div>`;
  }
  $('#facGrid').innerHTML = html;
  $('#facFloor').textContent = `${FAC.floor}-${T.fl}`;
  // квартиры этажа — цветными кнопками: номер, комнат, сколько у неё окон
  $('#facApts').innerHTML = unitsList().filter((u) => u.f === FAC.floor).map((u) => {
    const pl = L.placeOf(u.id), n = Object.values((pl && pl.wins) || {}).reduce((a, b) => a + b, 0);
    return `<button type="button" class="lc-apt${sel && sel.id === u.id ? ' on' : ''}" data-u="${esc(u.id)}"><i style="background:${colorOf(u)}">${esc(u.num)}</i><span>${u.r ? u.r + esc(T.rooms) : esc(T.k)}<small>${esc(T.winsN.replace(':n', n))}</small></span></button>`;
  }).join('') || `<span class="lc-ptext">${esc(T.noFlats)}</span>`;
}
/** Щелчок по окну: у выбранной квартиры его нет — добавить, есть — убрать; протянуть — так же по соседним окнам */
function facDown(e) {
  const v = e.target.closest('.fv');
  if (v) { if (v.dataset.fl !== '') facFloor(+v.dataset.fl); return; }
  const c = e.target.closest('.fc');
  if (!c) return;
  e.preventDefault();
  const k = +c.dataset.k, own = L.facadeOwners(FAC.face, FAC.floor), sel = L.selected();
  if (!sel || sel.f !== FAC.floor) { if (own[k]) pick(own[k]); return; }
  const add = own[k] !== sel.id;
  FAC.paint = { add, done: new Set([k]) };
  L.paint(sel.id, FAC.face, [k], add); facDraw();
}
function facMove(e) {
  const P = FAC.paint;
  if (!P || !(e.buttons & 1)) return;
  const c = document.elementFromPoint(e.clientX, e.clientY)?.closest('.fc');
  if (!c) return;
  const k = +c.dataset.k, sel = L.selected();
  if (P.done.has(k) || !sel) return;
  P.done.add(k); L.paint(sel.id, FAC.face, [k], P.add); facDraw();
}
const winFill = () => { if (!$('#wF')) return; $('#wF').value = L.winCount('f'); $('#wB').value = L.winCount('b'); $('#wS').value = L.winCount('s'); };
function card(u) {
  $('#uCard').hidden = false;
  $('#uHead').innerHTML = `<b><i class="lc-dot" style="background:${colorOf(u)}"></i>${esc(u.label)}</b><small>${esc(u.sub)}${u.s && T.st[u.s] ? ' · ' + esc(T.st[u.s]) : ''}</small>`;
  const pl = L.placeOf(u.id), w = (pl && pl.wins) || {};
  const sides = [['f', T.sF], ['b', T.sB], ['r', T.sR], ['l', T.sL]].filter(([k]) => w[k]).map(([k, n]) => `${n}: <b>${w[k]}</b>`).join(' · ');
  $('#uPlace').innerHTML = ICON.pin + `<span>${sides ? T.winsOn + ' ' + sides + '. ' : ''}${pl && pl.slot ? (pl.own || pl.slot.m ? T.placedHand : T.placedAuto) : T.placedNone}</span>`;
  if ($('#uRooms')) {
    const locked = u.k !== 'flat' || u.s === 'sold' || u.s === 'booked';
    $('#uRooms').value = u.r || ''; $('#uArea').value = u.a || '';
    $('#uRooms').disabled = $('#uArea').disabled = locked;
    document.querySelectorAll('#uRoomsStep button').forEach((b) => { b.disabled = locked; });
    $('#uSave').hidden = true;
  }
  const list = innerOf(u), cnt = (k) => list.filter((m) => m.k === k).length;
  $('#uInMini').innerHTML = `<span>${esc(T.inside)}:</span>` + (list.length ? ['interior', 'pano', 'photo', 'plan'].filter(cnt).map((k) => `<span>${esc(T.kindsShort[k])} <b>${cnt(k)}</b></span>`).join(' · ') : `<span class="muted">${esc(T.nothing)}</span>`)
    + `<span style="flex:1"></span>${list.length ? `<button type="button" class="btn btn-s btn-p" data-a="view">${ICON.eye}${esc(T.look)}</button>` : ''}${UP_URL ? `<button type="button" class="btn btn-s" data-a="up">${ICON.up}${esc(T.addInner)}</button>` : ''}`;
}

/* ---------- шаг 3: интерьер по планировкам — общий модуль assets/inner-media.js (загрузить, поправить, удалить) ---------- */
function prog(k, text) {
  const p = $('#uProg'), t = $('#uProgT');
  if (!p) return;
  p.hidden = t.hidden = k === false;
  if (k !== null && k !== false) p.firstElementChild.style.width = Math.round(k * 100) + '%';
  if (text) t.textContent = text;
}
function legend() {
  return `<div class="lc-leg">${[['free', '#22c55e'], ['booked', '#f59e0b'], ['sold', '#ef4444']].map(([k, c]) => `<span><i style="background:${c}"></i>${T.st[k]}</span>`).join('')}</div>`;
}
function fill(c) {
  if (!c || !$('#cL')) return;
  $('#cL').value = c.s[0].toFixed(1); $('#cH').value = c.s[1].toFixed(1); $('#cD').value = c.s[2].toFixed(1);
  $('#cR').value = Math.round(c.r * 180 / Math.PI); $('#cF').value = c.floors; $('#cG').value = c.gf > 0 ? c.gf : ''; $('#cRows').value = c.rows; $('#cBack').value = c.back;
  $('#cFlipX').classList.toggle('btn-soft', !!c.flipX); $('#cFlipZ').classList.toggle('btn-soft', !!c.flipZ);
}
</script>
@endif
</body>
</html>
