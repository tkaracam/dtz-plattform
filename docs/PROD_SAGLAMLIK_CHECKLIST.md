# Prod Sağlamlık Checklist (DTZ-LiD)

Durum güncelleme tarihi: 26.03.2026 (güncel)

Not:
- Aşağıdaki işaretlemeler teknik doğrulama (kod ve smoke çıktısı) üzerinden yapıldı.
- Login gerektiren bazı maddeler credential ile tam E2E doğrulama bekliyor.

## 1) Deploy ve Sürüm
- [ ] `main` branch protection aktif (force-push kapalı).
- [ ] Deploy öncesi zorunlu CI pass var.
- [ ] Her deploy için izlenebilir sürüm etiketi var (`release-YYYYMMDD-HHMM`).
- [ ] Rollback prosedürü yazılı ve son 2 release için test edilmiş.

## 2) Konfigürasyon ve Secret Yönetimi
- [ ] API anahtarları sadece environment variable olarak tutuluyor.
- [ ] Prod/staging env ayrımı net.
- [ ] `.env` ve private config dosyaları git dışında.
- [ ] Secret rotasyonu (kim, ne zaman, nasıl) dokümante.

## 3) Auth / Yetki
- [ ] Hauptadmin / Dozent / Schüler oturumları izole.
- [ ] Kritik endpointlerde role guard doğrulandı (`403` beklenen yerde).
- [ ] Login rate-limit ve brute-force koruması aktif.
- [ ] Session cookie güvenlik ayarları (`HttpOnly`, `SameSite`, mümkünse `Secure`) doğrulandı.

## 4) Veri Bütünlüğü
- [x] JSON write işlemleri lock ile yapılıyor.
- [ ] Batch create/delete akışlarında kısmi bozulma durumu test edildi.
- [x] Ödev target modeli frozen (`assignees`) ve geriye dönük genişleme yok.
- [ ] Dosya bozulması durumunda restore prosedürü var.

Not:
- Batch `create` akışı all-or-nothing olacak şekilde atomik hale getirildi.
- `delete` ve eşzamanlı yarış senaryoları için otomatik test halen açık.

## 5) Ödev / Sınav Kuralları
- [x] Deadline + active + submitted server-side enforce ediliyor.
- [x] DTZ/Modelltest sonuç payload anti-tamper doğrulanıyor.
- [x] Silinen ödev sorularının tekrarını düşüren blok mekanizması aktif.
- [x] Atanan ödevde yasak buton/akışlar (Neue Aufgaben/Teil geçişi vb.) kilitli.

## 6) Frontend Stabilite
- [ ] Schüler login sonrası doğru landing (portal) çalışıyor.
- [x] A1/A2/B1 assignment iframe akışları sorunsuz.
- [ ] Modelltest faz geçişleri (Hören → Lesen → Schreiben) stabil.
- [ ] Mobilde kritik ekranlarda yatay taşma yok.

## 7) Gözlemlenebilirlik
- [ ] `audit_log` aksiyonları kritik akışları kapsıyor.
- [ ] 5xx oranı, login başarısızlığı, assignment hata oranı izleniyor.
- [x] Deploy sonrası ilk 30 dakika için hızlı health kontrol var.

Not:
- `Fortschritt` ekranında reminder kırılımı geliştirildi: course/template/level + kurs-detay popup.

## 8) Yedekleme ve Kurtarma
- [ ] `api/storage/*` için düzenli backup var.
- [ ] Son 7 günlük geri dönüş noktası saklanıyor.
- [ ] Aylık restore tatbikatı yapılıyor.

## 9) Performans
- [ ] Eşzamanlı kullanıcı (ör. 50+) altında temel akışlar test edildi.
- [ ] Assignment batch ve portal listeleri yük altında kontrol edildi.
- [ ] Kritik endpoint yanıt süreleri kabul sınırında.

## 10) Operasyonel Kabul Kriteri (Go/No-Go)
- [ ] Kritik testler %100 geçer.
- [ ] Majör testler >= %95 geçer.
- [ ] Deploy sonrası 5xx oranı < %1.
- [ ] Geri alma süresi < 10 dakika.
