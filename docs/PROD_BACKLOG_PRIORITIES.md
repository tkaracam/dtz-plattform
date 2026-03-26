# Prod Backlog Önceliklendirme (26.03.2026)

## P1 (Önce bunlar)
1. Deadline + active + submitted hard-lock için tüm sonuç endpointlerini (özellikle modelltest) tam enforce.
2. Sonuç payload anti-tamper server doğrulaması (correct/wrong/total/points/level tutarlılığı).
3. Deploy gate: CI başarısızsa deploy bloklanmalı.
4. Role/auth E2E doğrulama (Hauptadmin/Dozent/Schüler session isolation).

## P2
1. Batch create/delete yarış senaryoları için otomatik API testleri.
2. 5xx, login fail, assignment fail metriklerinin dashboard/alert entegrasyonu.
3. Mobil kırılma test paketi (kritik ekranlar için viewport matrix).
4. Modelltest akışının uçtan uca otomatik testi.

## P3
1. Backup/restore playbook dokümantasyonu ve aylık tatbikat otomasyonu.
2. Performans yük testleri (50+ eşzamanlı öğrenci).
3. Release tagging ve rollback drill standardizasyonu.

## Uygulama Sırası
1. P1-1 + P1-2
2. P1-3
3. P1-4
4. P2 seti
5. P3 seti
