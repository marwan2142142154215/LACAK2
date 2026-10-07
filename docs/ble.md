# SMB BLE Proximity Protocol

Status: **PHASE 19** — diimplementasikan di kode, **radio BLE fisik belum diverifikasi end-to-end**
(lihat "Keterbatasan saat ini" di bawah dan DEC-003/DEC-007 di `docs/DECISIONS.md`).

## Peran

| Komponen | Peran BLE | Kelas |
|---|---|---|
| SMB Master (`smb-master-android`) | Central / Scanner | `com.smb.master.ble.BleCentralScanner` |
| SMB Lacak (`smb-tracker-android`) | Peripheral / Advertiser | `com.smb.lacak.ble.BlePeripheralAdvertiser` |

Konstanta protokol didefinisikan di `BleProximityProtocol` pada **kedua** project (duplikasi
disengaja — lihat §81: dua app Android independen, bukan Gradle module bersama). Setiap
perubahan ke salah satu WAJIB disinkronkan manual ke yang lain.

## Service UUID & advertisement

```
Service UUID      : 6b3a0001-7b7a-4e62-9c3e-2f4f6a9d0001
Manufacturer ID    : 0xFFFF (unregistered/testing — belum request Company ID resmi Bluetooth SIG)
Manufacturer data  : 16 byte ephemeral identifier acak (SecureRandom)
Connectable        : false (advertisement-only, tidak ada GATT server)
Device name        : TIDAK disertakan (setIncludeDeviceName(false))
```

§16 BLE SECURITY — advertisement **TIDAK PERNAH** berisi `device_id`, `registration_code`,
`access_token`, `device_secret`, atau secret apa pun. Ephemeral identifier dirotasi setiap
`EPHEMERAL_ROTATION_INTERVAL_MS` (15 menit) oleh Lacak, memakai `SecureRandom` — bukan
turunan deterministik dari device_id (supaya tidak bisa di-reverse ke identitas asli).

## Alur scan (Master)

```
startScanning()
  -> cek BluetoothAdapter enabled?           tidak -> BleScanState.BleDisabled
  -> cek permission BLUETOOTH_SCAN/LOCATION? tidak -> BleScanState.PermissionRequired
  -> ScanFilter(serviceUuid = SERVICE_UUID)
  -> ScanSettings.SCAN_MODE_BALANCED (§73 battery-aware, bukan LOW_LATENCY terus-menerus)
  -> BleScanState.Scanning
  -> onScanResult -> RssiSmoother.addSample() -> ProximityClassifier.classify()
  -> NearbyDevice diperbarui di StateFlow
  -> pruneStaleDevices() dipanggil periodik (5 detik) -> hapus device yang > 30 detik tidak terdeteksi (NOT_DETECTED_TIMEOUT_MS)
```

## RSSI smoothing & proximity

RSSI mentah **sangat noisy** (multipath, orientasi, interferensi) — tidak pernah dipakai
langsung. `RssiSmoother` adalah exponential moving average (alpha default 0.3):

```
smoothed(t) = alpha * rssi_raw(t) + (1 - alpha) * smoothed(t-1)
```

`ProximityClassifier` mengklasifikasi RSSI yang sudah di-smoothing ke 5 level (§15):

| Level | Threshold (smoothed RSSI) |
|---|---|
| VERY_NEAR | >= -50 dBm |
| NEAR | >= -65 dBm |
| MEDIUM | >= -80 dBm |
| FAR | < -80 dBm |
| NOT_DETECTED | tidak ada observasi (timeout) |

`estimatedDistanceMeters()` memakai formula log-distance path loss generik
(`n=2`, RSSI referensi -59dBm @ 1m) — **KALIBRASI KASAR, bukan hasil pengukuran per-chipset
nyata**. UI WAJIB menampilkan label "Perkiraan jarak" (§15), tidak pernah "jarak" tanpa
kualifikasi.

## Battery strategy (§73)

- Scan: `SCAN_MODE_BALANCED` (bukan `SCAN_MODE_LOW_LATENCY` terus-menerus)
- Advertise: `ADVERTISE_MODE_BALANCED`, `ADVERTISE_TX_POWER_MEDIUM`, non-connectable
- Rotasi identifier setiap 15 menit (bukan tiap detik) — cukup untuk privasi tanpa membebani CPU/radio

## Recovery (§17/§126)

| Kondisi | State | Recovery |
|---|---|---|
| Bluetooth OFF | `BleScanState.BleDisabled` / `BleAdvertiseState.BleDisabled` | Deteksi ulang saat `startScanning()`/`startAdvertising()` dipanggil lagi (dipicu buka tab Radar BLE di Master, atau restart agent service di Lacak) |
| Permission belum diberikan | `PermissionRequired` | UI menampilkan tombol "Berikan izin" (Master) — tidak crash |
| Chipset tidak mendukung advertising | `BleAdvertiseState.Unsupported` | Dilaporkan jujur ke capability report (`ble_advertising_supported=false`) — BUKAN fake success |
| Device hilang dari radar | Entry dihapus dari `nearbyDevices` setelah 30 detik | Muncul lagi otomatis begitu scan result diterima lagi |

## Keterbatasan saat ini (jujur, §88)

1. **Belum ada verifikasi identitas kriptografis** (GATT challenge/response, §16 "jika
   melakukan connection-level verification"). Baseline saat ini (ephemeral identifier yang
   tidak berisi secret) sudah memenuhi §16, tapi Master TIDAK bisa memastikan secara
   kriptografis bahwa satu ephemeral ID tertentu benar-benar milik device_id X — radar BLE
   saat ini adalah discovery/proximity murni, bukan autentikasi device.
2. **Radio BLE fisik belum diuji end-to-end** di lingkungan sesi manapun — tidak ada
   device/emulator dengan radio Bluetooth nyata yang tersedia untuk sesi otomatis ini.
   Logika murni (smoothing, klasifikasi) sudah diuji lewat JVM unit test
   (`RssiSmootherTest`, `ProximityClassifierTest`); verifikasi Master↔Lacak lewat
   Bluetooth sungguhan di dua perangkat fisik **masih harus dilakukan pemilik produk**
   sebelum PHASE 19 dinyatakan DONE (§78).
3. Company ID manufacturer data (`0xFFFF`) adalah placeholder testing — production
   sebaiknya mendaftar Company ID resmi ke Bluetooth SIG kalau butuh interoperabilitas
   dengan scanner pihak ketiga (di luar scope SMB sendiri, tidak wajib untuk sistem tertutup ini).
