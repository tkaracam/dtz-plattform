# DTZ Hören/Lesen Audit – Phase 1

Bu not, DTZ Hören/Lesen modülünün mevcut yapısını ve gerçek DTZ görev mantığına uyumsuz noktaları tespit etmek için hazırlanmıştır.

## 1) Mevcut component yapısı (frontend)

Kaynak: `/Users/Tolga/Documents/GitHub/dtz-plattform/index.html`

- Ana kart: `#dtzTrainingCard`
- Üst akış: `Hören (Teil 1-4)` ve `Lesen (Teil 1-5)`
- Runtime state: `currentDtzStates.hoeren` / `currentDtzStates.lesen`
- Konfigürasyon: `DTZ_PART_CONFIG` (Teil başlıkları, açıklamalar, count)  
  - Kanıt: `index.html:2942`
- UI erişimi: `getDtzUi(module)`  
  - Kanıt: `index.html:4558`
- Veri yükleme: `loadDtzTrainingSet(module)` → `./api/student_training_set.php`  
  - Kanıt: `index.html:5382`, `index.html:5393`
- Görev dönüştürme: `buildDtzTasks(module, teil, rawItems)`  
  - Kanıt: `index.html:4703`
- Render:
  - Generic single-choice: `renderDtzSingleChoiceTask`  
    - Kanıt: `index.html:5165`
  - Özel renderer: `renderDtzLesenTeil2Task` (matching)  
    - Kanıt: `index.html:5209`
  - Ana render orkestrasyonu: `renderDtzTrainingSet`  
    - Kanıt: `index.html:5305`
- Değerlendirme: `evaluateDtzTrainingSet`  
  - Kanıt: `index.html:5332`

## 2) Hören/Lesen için ortak generic renderer var mı?

Evet, büyük ölçüde var.

- Hören tüm teiller `single_choice` tabanlı generic renderer ile çiziliyor.
- Lesen’de Teil 1/3/4/5 generic single-choice, sadece Teil 2 özel matching renderer kullanıyor.
- Yani mimari "teil-bazlı tam renderer" değil; "generic + bir istisna" modelinde.

Kanıt:
- `buildDtzHoerenTasksByTeil`: `index.html:4654`
- `buildDtzLesenSingleChoiceTasksByTeil`: `index.html:4679`
- Lesen Teil 2 özel dalı: `index.html:4711`

## 3) Mevcut soru tipleri (aktif)

- Multiple choice (A/B/C): **Var** (ana tip)
- Doğru/yanlış: **Yok** (ayrı tip olarak yok)
- Matching (A-H/X): **Var** (sadece Lesen Teil 2)
- Cloze / boşluk doldurma: **Yok** (ayrı tip olarak yok)
- Audio-based task: **Kısmen var**  
  - Hören için metin `audio_script/text` geliyor ve frontend “Audio abspielen” butonu üzerinden TTS çalıştırıyor.

Kanıt:
- Single choice option render: `index.html:5151`
- Lesen T2 matching: `index.html:5209`
- Audio control butonu: `index.html:5189`

## 4) Veri kaynağı nasıl tutuluyor?

Aktif üretim yolu:
- Backend’de statik/template PHP dizileri (`training_set_lib.php`) ile set üretiliyor.
- Endpoint: `api/student_training_set.php` -> `create_training_set(...)`

Kanıt:
- `student_training_set.php:56`
- `training_set_lib.php:1397`

Not:
- `assets/content/dtz_lesen_hoeren_template_bank.json` dosyası var.
- Ancak bu dosyayı okuyan `load_training_template_bank()` fonksiyonu aktif üretim akışında kullanılmıyor.

Kanıt:
- Tanım var: `training_set_lib.php:4`, `training_set_lib.php:9`
- Çağrı yok (tek dosyada tanım olarak kalmış).

## 5) Teil bazında yetersiz alanlar

Mevcut item şeması (özet):
- `dtz_part, task_type, context, title, instructions, text, audio_script, question, options, correct, explanation`
- Kanıt: `training_set_lib.php:1431-1444`

Bu şema gerçek DTZ Teil mantığı için yetersiz kalıyor:

1. `audio source`
- Ayrı gerçek dosya URL’si/asset id’si yok.
- Sadece script-text var (`audio_script`), bu da doğal çok konuşmacılı senaryo yönetimini zorlaştırıyor.

2. `transcript`
- Metin var ama transcript metadata (speaker-turn, timecode, varyant) yok.

3. `options`
- Generic map olarak var; ama bazı teillerde grouped option set veya section-level option pool gerekiyor.

4. `correct answer`
- Tek cevap harfi var; çok sorulu tek stimulus gruplarında item-level/group-level ayrımı eksik.

5. `mapping pairs`
- Sadece Lesen Teil 2’de kısmi mevcut (`ads + situations`); genel bir task tipi olarak şemalaşmamış.

6. `letter-based options`
- A/B/C standardı var, A-H/X özeli sadece bir istisna dalı; diğer teil ihtiyaçları için genellenmemiş.

7. `X / keine Lösung`
- Sadece Lesen T2 özel dalında var; core modelde standart alan değil.

8. `grouped text blocks`
- Aynı metne bağlı 5 soru gibi grup yapıları için explicit `stimulus_group` modeli yok.

9. `intro instructions`
- Instruction var ama per-set/per-group/per-task hiyerarşisi zayıf; DTZ teil yönergeleri için ayrışma net değil.

## 6) Neden tek ortak `question[]` yaklaşımı DTZ’ye uymuyor?

DTZ’de her Teil farklı ölçüm davranışına sahiptir:
- Farklı stimulus tipi (kısa anons, diyalog, ilan/situasyon, uzun metin)
- Farklı eşleme mantığı (özellikle A-H/X türü)
- Bazı teillerde aynı metne bağlı çoklu soru grubu
- Bölüme özel yönerge ve puanlama nüansı

Tek bir düz `question[]` + generic render yaklaşımı:
- Teil davranışlarını data-contract seviyesinde net ayıramaz,
- Renderer’ı koşul/if ile büyütür,
- Test üretimi ve doğrulama mantığını kırılgan hale getirir,
- Yeni Teil eklendiğinde bakım maliyetini yükseltir.

## 7) Önerilen yeni mimari (refactor hedefi)

### 7.1 Task-Definition katmanı
Her Teil için ayrı task şeması:

- `hoeren_teil1_short_announcement`
- `hoeren_teil2_dialog`
- `hoeren_teil3_info_talk`
- `hoeren_teil4_intent_opinion`
- `lesen_teil1_short_text`
- `lesen_teil2_matching_anzeigen`
- `lesen_teil3_functional_text`
- `lesen_teil4_intent_opinion`
- `lesen_teil5_long_text`

### 7.2 Renderer registry
- `renderers[task.type] = fn(task, state)`
- Generic renderer sadece gerçekten ortak tiplerde kullanılmalı.
- Lesen T2 gibi özel yapı istisna olmaktan çıkıp standart task-type olmalı.

### 7.3 Evaluator registry
- `evaluators[task.type] = fn(task, answers)`
- Teil bazlı scoring/validation net ayrılmalı.

### 7.4 Data contract
Ortak çekirdek + type-specific payload:
- core: `id, module, teil, type, title, instructions, points`
- payload:
  - audio task: `audio {src, transcript, speakers[]}`
  - matching task: `pool, prompts, allowX`
  - grouped reading: `stimulus, questions[]`

### 7.5 API ayrımı
- `student_training_set.php` aynı kalabilir; fakat dönen `items` artık type-safe payload içermeli.
- İleride `GET /training/schema` gibi endpoint ile frontend şema uyumluluğu doğrulanabilir.

---

## Kısa sonuç

Mevcut sistemde DTZ UI görünümü doğru yönde, ancak görev motoru hâlâ "generic single-choice merkezli".  
Gerçek DTZ mantığı için bir sonraki aşamada **Teil-bazlı task schema + renderer/evaluator registry** mimarisine geçiş önerilir.

---

## Phase 3 Uygulama Notu (Tamamlanan Refactor)

Bu audit sonrası frontend tarafında aşağıdaki refactor uygulanmıştır:

1. State artık modül-genel değil **Teil-bazlı registry** yapısında tutuluyor.
   - `currentDtzStates.{hoeren|lesen}.teils[teilNo]`
   - Her Teil kendi `tasks/answers/evaluated` verisini bağımsız saklıyor.

2. Render ve evaluate akışı **Teil bazlı registry map** ile ayrıştırıldı.
   - `DTZ_RENDERERS[module][teil]`
   - `DTZ_EVALUATORS[module][teil]`

3. Lesen Teil 2 eşleştirme görevi ayrı task tipi olarak korunup generic akıştan ayrıldı.

4. UI/akış:
   - Ana başlık: `DTZ Hören/Lesen Training`
   - Sıra: önce Hören (Teil 1-4), sonra Lesen (Teil 1-5)
   - Her modülde değerlendirme butonu soru listesinin altında çalışıyor.

5. Mimariyi okunur kılmak için framework’süz component yardımcıları eklendi:
   - `DtzTrainingPage`, `DtzAccordion`, `HoerenSection`, `LesenSection`
   - `SectionHeader`, `TaskInstruction`, `AudioPlayer`, `OptionGroup`, `ResultBox` vb.
