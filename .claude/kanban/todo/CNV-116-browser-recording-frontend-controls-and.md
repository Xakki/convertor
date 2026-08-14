### Frontend-управление записью и consent для browser recording

**Criticality:** High

**TAGS:**
- feature
- browser
- consent
- frontend
- recording

**Description:**
Frontend-специалист добавляет интерфейс URL recording в WebM, отображает
server-defined network preset и плановые лимиты, а также получает явный consent
пользователя перед отправкой записи удалённой страницы.

**Problem:**
Recording удалённого URL может затрагивать сторонний контент и потребляет ограниченный
browser runtime. Без явного consent и понятного UI пользователь не подтверждает
ответственность за URL, а ограничения runtime выглядят непредсказуемыми.

**Impact:**
Пользователь может непреднамеренно запускать запись без уведомления о стороннем
контенте и лимитах; свободные планы могут увидеть недоступные controls или неясный
отказ.

**Recommendation:**
Сделать URL-only форму recording с server-defined preset `normal|fast_3g|slow_3g`,
отображением WebM/no-audio, доступных plan limits, progress и результатов. До submit
потребовать отдельное явное подтверждение, что пользователь уполномочен записывать
указанный URL и понимает передачу URL в browser service; без подтверждения submit
заблокирован. Не реализовывать recording runtime, proxy, API policy, произвольные
network numbers, HTML recording или MP4.

**Acceptance Criteria:**
- Recording UI доступен basic/pro; guest/free видят предсказуемый plan denial и не
  могут отправить recording-задачу.
- Submit доступен только после явного consent; изменение URL сбрасывает consent, а
  текст уведомления сообщает URL-only scope, сторонний контент, WebM/no-audio и
  server limits.
- UI принимает только server presets, показывает Basic 15 s/15 FPS/25 MB и Pro
  30 s/24 FPS/50 MB, отображает progress/result и безопасные ошибки без утечки URL.
- Component/integration/e2e tests покрывают consent, reset consent, plan gates,
  presets, success и error states; целевые frontend-проверки проходят без новых
  предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: frontend-специалист; граница работы — recording UI и user consent.
- Consent является явным per-URL действием UI и не заменяет server-side URL policy,
  plan gate либо proxy enforcement.
- CNV-116 зависит от готового recording runtime CNV-91 и URL contract CNV-89;
  CNV-88, CNV-113 и CNV-114 для него являются транзитивными prerequisites.
