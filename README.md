# Dokumentasi API: Manajemen Perangkat Pengguna

Dokumentasi ini menjelaskan endpoint API untuk autentikasi pengguna dan manajemen perangkat yang terdaftar dalam sistem.

**Tanggal Dokumen:** 12 Mei 2025

## 1. Informasi Umum

### 1.1. Base URL
Semua URL yang dirujuk dalam dokumentasi ini menggunakan base URL berikut:
http://localhost:8000/api*Catatan: Ganti `http://localhost:8000` dengan domain produksi Anda.*

### 1.2. Metode Autentikasi
API ini menggunakan **Laravel Sanctum Bearer Tokens** untuk autentikasi. Setelah berhasil login, klien akan menerima `access_token`. Token ini harus dikirimkan pada setiap request ke endpoint yang terproteksi melalui header `Authorization`.

### 1.3. Header Wajib untuk Endpoint Terproteksi
* **`Accept: application/json`**
* **`Authorization: Bearer <YOUR_ACCESS_TOKEN>`**
* **`X-Device-ID: <CLIENT_DEVICE_IDENTIFIER>`**: Header ini wajib untuk semua endpoint yang dilindungi oleh middleware `verified.device`. Nilainya adalah identifier unik perangkat klien (misalnya, hasil dari FingerprintJS untuk browser, atau UUID yang digenerate di mobile).

### 1.4. Format Request dan Response
Semua request body dan response body menggunakan format **JSON**.

### 1.5. Kode Status HTTP Umum
* `200 OK`: Request berhasil.
* `201 Created`: Resource berhasil dibuat (misalnya, saat admin mendaftarkan perangkat).
* `204 No Content`: Request berhasil namun tidak ada konten untuk dikembalikan (jarang digunakan di API ini).
* `400 Bad Request`: Request tidak valid karena parameter hilang atau salah format (misalnya, `X-Device-ID` hilang).
* `401 Unauthorized`: Autentikasi gagal atau token tidak valid/hilang.
* `403 Forbidden`: Pengguna terautentikasi tetapi tidak memiliki izin untuk mengakses resource tersebut (misalnya, perangkat tidak disetujui, akses admin ditolak).
* `404 Not Found`: Resource yang diminta tidak ditemukan.
* `422 Unprocessable Entity`: Validasi gagal untuk data yang dikirim (misalnya, email tidak valid saat login).
* `500 Internal Server Error`: Terjadi kesalahan di sisi server.

---

## 2. Endpoint Autentikasi & Pengguna (`/api`)

### 2.1. Login Pengguna
Endpoint ini digunakan untuk pengguna login ke sistem dan mendapatkan token akses. Perangkat akan didaftarkan dengan status `pending` jika baru, atau statusnya akan dicek jika sudah ada. Hanya perangkat dengan status `approved` yang bisa melanjutkan login.

* **URL**: `/login`
* **Metode**: `POST`
* **Headers**:
    * `Accept: application/json`
    * `Content-Type: application/json`
* **Request Body**:
    ```json
    {
        "email": "user@example.com",
        "password": "password123",
        "device_identifier": "unique_browser_fingerprint_or_mobile_uuid",
        "device_name": "Chrome on Windows / My Android Phone"
    }
    ```
    * `email` (string, required): Alamat email pengguna.
    * `password` (string, required): Kata sandi pengguna.
    * `device_identifier` (string, required): Identifier unik dari perangkat klien.
    * `device_name` (string, optional): Nama deskriptif untuk perangkat klien.

* **Response Sukses (200 OK - Perangkat Disetujui)**:
    ```json
    {
        "message": "Login successful",
        "access_token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
        "token_type": "Bearer",
        "user": {
            "id": 1,
            "name": "Nama Pengguna",
            "email": "user@example.com"
        },
        "device": {
            "id": 1,
            "device_identifier": "unique_browser_fingerprint_or_mobile_uuid",
            "name": "Chrome on Windows",
            "status": "approved"
        }
    }
    ```

* **Response Gagal (403 Forbidden - Perangkat Pending/Ditolak/Dicabut)**:
    ```json
    {
        "message": "Device registration request received. Please wait for admin approval.", // atau pesan status lainnya
        "errors": {
            "device_status": ["Device registration request received. Please wait for admin approval."] // atau pesan status lainnya
        }
    }
    ```
    *Catatan: `message` dan `errors.device_status` akan bervariasi tergantung status perangkat.*

* **Response Gagal (422 Unprocessable Entity - Validasi Kredensial Gagal)**:
    ```json
    {
        "message": "The given data was invalid.",
        "errors": {
            "email": [
                "These credentials do not match our records."
            ]
        }
    }
    ```

### 2.2. Mendapatkan Detail Pengguna Saat Ini
Endpoint ini mengembalikan detail pengguna yang sedang login dan informasi perangkat yang digunakan.

* **URL**: `/user`
* **Metode**: `GET`
* **Headers**:
    * `Accept: application/json`
    * `Authorization: Bearer <YOUR_ACCESS_TOKEN>`
    * `X-Device-ID: <CLIENT_DEVICE_IDENTIFIER>`
* **Request Body**: Tidak ada.
* **Response Sukses (200 OK)**:
    ```json
    {
        "user": {
            "id": 1,
            "name": "Nama Pengguna",
            "email": "user@example.com"
        },
        "current_device_info": {
            "id": 1,
            "device_identifier": "unique_browser_fingerprint_or_mobile_uuid",
            "name": "Chrome on Windows",
            "status": "approved"
        }
    }
    ```

### 2.3. Logout Pengguna
Endpoint ini digunakan untuk logout pengguna dan mencabut token akses yang sedang digunakan.

* **URL**: `/logout`
* **Metode**: `POST`
* **Headers**:
    * `Accept: application/json`
    * `Authorization: Bearer <YOUR_ACCESS_TOKEN>`
    * `X-Device-ID: <CLIENT_DEVICE_IDENTIFIER>` (Opsional untuk logout, tapi baik untuk konsistensi)
* **Request Body**: Tidak ada.
* **Response Sukses (200 OK)**:
    ```json
    {
        "message": "Logged out successfully"
    }
    ```

### 2.4. Melihat Daftar Perangkat Milik Pengguna
Endpoint ini mengembalikan daftar perangkat yang terdaftar untuk pengguna yang sedang login.

* **URL**: `/my-devices`
* **Metode**: `GET`
* **Headers**:
    * `Accept: application/json`
    * `Authorization: Bearer <YOUR_ACCESS_TOKEN>`
    * `X-Device-ID: <CLIENT_DEVICE_IDENTIFIER>`
* **Request Body**: Tidak ada.
* **Response Sukses (200 OK)**:
    ```json
    [
        {
            "id": 1,
            "name": "Chrome on Windows",
            "device_identifier": "fingerprint_abc",
            "status": "approved",
            "last_used_at": "2025-05-12T10:00:00.000000Z",
            "admin_notes": "Device approved by admin."
        },
        {
            "id": 2,
            "name": "My Android Phone",
            "device_identifier": "uuid_xyz",
            "status": "pending",
            "last_used_at": null,
            "admin_notes": null
        }
    ]
    ```

---

## 3. Endpoint Manajemen Perangkat oleh Admin (`/api/admin`)
Semua endpoint di bawah ini memerlukan autentikasi sebagai admin dan perangkat admin juga harus terverifikasi (tergantung konfigurasi middleware `is.admin` dan `verified.device` untuk admin).

### 3.1. Melihat Semua Perangkat Terdaftar (Admin)
Endpoint ini mengembalikan daftar semua perangkat yang terdaftar di sistem, dengan opsi filter berdasarkan status.

* **URL**: `/admin/devices`
* **Metode**: `GET`
* **Headers**:
    * `Accept: application/json`
    * `Authorization: Bearer <ADMIN_ACCESS_TOKEN>`
    * `X-Device-ID: <ADMIN_CLIENT_DEVICE_IDENTIFIER>`
* **Query Parameters**:
    * `status` (string, optional): Filter berdasarkan status perangkat (misalnya, `pending`, `approved`, `rejected`, `revoked`).
    * `page` (integer, optional): Untuk paginasi.
* **Response Sukses (200 OK)**:
    ```json
    {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "user_id": 1,
                "device_identifier": "fingerprint_abc",
                "name": "Chrome on Windows (User A)",
                "status": "approved",
                "approved_by": 2, // Admin ID
                "approved_at": "2025-05-11T12:00:00.000000Z",
                "admin_notes": "Device approved.",
                "last_login_ip": "192.168.1.10",
                "last_used_at": "2025-05-12T10:00:00.000000Z",
                "created_at": "2025-05-11T11:00:00.000000Z",
                "updated_at": "2025-05-12T10:00:00.000000Z",
                "user": {
                    "id": 1,
                    "name": "User A",
                    "email": "usera@example.com"
                },
                "approver": {
                    "id": 2,
                    "name": "Admin Name"
                }
            },
            // ... perangkat lainnya
        ],
        "first_page_url": "http://localhost:8000/api/admin/devices?page=1",
        // ... properti paginasi lainnya
    }
    ```

### 3.2. Melihat Detail Satu Perangkat (Admin)
Endpoint ini mengembalikan detail spesifik dari satu perangkat.

* **URL**: `/admin/devices/{device_id}`
* **Metode**: `GET`
* **Headers**: (Sama seperti 3.1)
* **Path Parameters**:
    * `device_id` (integer, required): ID dari `UserDevice`.
* **Response Sukses (200 OK)**:
    ```json
    {
        "id": 1,
        "user_id": 1,
        // ... semua field dari UserDevice
        "user": {
            "id": 1,
            "name": "User A",
            "email": "usera@example.com"
        },
        "approver": {
            "id": 2,
            "name": "Admin Name"
        }
    }
    ```

### 3.3. Menyetujui Perangkat (Admin)
Endpoint ini digunakan oleh admin untuk menyetujui perangkat yang statusnya `pending`. Jika pengguna sudah memiliki perangkat lain yang `approved`, perangkat lama akan otomatis di-`revoke` (kebijakan satu perangkat aktif per pengguna).

* **URL**: `/admin/devices/{device_id}/approve`
* **Metode**: `POST`
* **Headers**: (Sama seperti 3.1)
* **Path Parameters**:
    * `device_id` (integer, required): ID dari `UserDevice` yang akan disetujui.
* **Request Body**:
    ```json
    {
        "notes": "Perangkat ini terlihat aman dan disetujui." // (string, optional)
    }
    ```
* **Response Sukses (200 OK)**:
    ```json
    {
        "message": "Device approved successfully. Any previous device for this user has been revoked.",
        "device": {
            "id": 1,
            "status": "approved",
            "approved_by": 2, // Admin ID yang melakukan aksi
            "approved_at": "2025-05-12T14:00:00.000000Z",
            "admin_notes": "Perangkat ini terlihat aman dan disetujui.",
            // ... field lainnya
        }
    }
    ```

### 3.4. Menolak Perangkat (Admin)
Endpoint ini digunakan oleh admin untuk menolak perangkat yang statusnya `pending`.

* **URL**: `/admin/devices/{device_id}/reject`
* **Metode**: `POST`
* **Headers**: (Sama seperti 3.1)
* **Path Parameters**:
    * `device_id` (integer, required): ID dari `UserDevice` yang akan ditolak.
* **Request Body**:
    ```json
    {
        "notes": "Perangkat tidak memenuhi standar keamanan." // (string, required)
    }
    ```
* **Response Sukses (200 OK)**:
    ```json
    {
        "message": "Device rejected successfully.",
        "device": {
            "id": 1,
            "status": "rejected",
            "admin_notes": "Perangkat tidak memenuhi standar keamanan.",
            // ... field lainnya
        }
    }
    ```
* **Response Gagal (422 Unprocessable Entity - `notes` tidak ada)**:
    ```json
    {
        "message": "The given data was invalid.",
        "errors": {
            "notes": ["The notes field is required."]
        }
    }
    ```

### 3.5. Mencabut Akses Perangkat (Admin)
Endpoint ini digunakan oleh admin untuk mencabut akses perangkat yang sebelumnya mungkin sudah `approved`.

* **URL**: `/admin/devices/{device_id}/revoke`
* **Metode**: `POST`
* **Headers**: (Sama seperti 3.1)
* **Path Parameters**:
    * `device_id` (integer, required): ID dari `UserDevice` yang akan dicabut.
* **Request Body**:
    ```json
    {
        "notes": "Perangkat dilaporkan hilang." // (string, optional)
    }
    ```
* **Response Sukses (200 OK)**:
    ```json
    {
        "message": "Device access revoked successfully.",
        "device": {
            "id": 1,
            "status": "revoked",
            "admin_notes": "Perangkat dilaporkan hilang.",
            // ... field lainnya
        }
    }
    ```

### 3.6. Mendaftarkan Perangkat Baru untuk Pengguna (Admin)
Endpoint ini memungkinkan admin untuk secara manual mendaftarkan dan langsung menyetujui perangkat baru untuk pengguna tertentu. Jika pengguna sudah memiliki perangkat lain yang `approved`, perangkat lama akan otomatis di-`revoke`.

* **URL**: `/admin/devices/register-for-user`
* **Metode**: `POST`
* **Headers**: (Sama seperti 3.1)
* **Request Body**:
    ```json
    {
        "user_id": 5, // (integer, required) ID pengguna yang akan didaftarkan perangkatnya
        "device_identifier": "new_manual_device_id_123", // (string, required)
        "device_name": "Laptop Kantor Baru", // (string, required)
        "notes": "Pendaftaran manual oleh admin." // (string, optional)
    }
    ```
* **Response Sukses (201 Created)**:
    ```json
    {
        "message": "Device registered and approved for user. Any previous device has been revoked.",
        "device": {
            "id": 10, // ID perangkat baru yang dibuat
            "user_id": 5,
            "device_identifier": "new_manual_device_id_123",
            "name": "Laptop Kantor Baru",
            "status": "approved",
            "approved_by": 2, // Admin ID yang melakukan aksi
            "approved_at": "2025-05-12T15:00:00.000000Z",
            "admin_notes": "Pendaftaran manual oleh admin.",
            // ... field lainnya
        }
    }
    ```
* **Response Gagal (422 Unprocessable Entity - Validasi gagal)**:
    ```json
    {
        "message": "The given data was invalid.",
        "errors": {
            "user_id": ["The selected user id is invalid."], // atau error validasi lainnya
            "device_identifier": ["The device identifier field is required."]
        }
    }
    ```

---

## 4. Model Data `UserDevice`
Berikut adalah field utama dari model `UserDevice` yang sering muncul dalam response:

* `id` (integer): ID unik perangkat.
* `user_id` (integer): ID pengguna pemilik perangkat.
* `device_identifier` (string): Identifier unik dari perangkat klien.
* `name` (string, nullable): Nama deskriptif perangkat.
* `status` (string): Status perangkat (`pending`, `approved`, `rejected`, `revoked`).
* `approved_by` (integer, nullable): ID admin yang menyetujui/menolak/mencabut.
* `approved_at` (timestamp, nullable): Waktu persetujuan.
* `admin_notes` (text, nullable): Catatan dari admin terkait perangkat.
* `last_login_ip` (string, nullable): IP address terakhir saat login dari perangkat ini.
* `last_used_at` (timestamp, nullable): Waktu terakhir perangkat ini digunakan untuk mengakses API.
* `created_at` (timestamp): Waktu pembuatan record.
* `updated_at` (timestamp): Waktu pembaruan record terakhir.
* `user` (object, opsional, dimuat dengan `with()`): Detail pengguna pemilik.
* `approver` (object, opsional, dimuat dengan `with()`): Detail admin yang melakukan aksi persetujuan/penolakan.

---

## 5. Contoh Pesan Error Umum dari Middleware `VerifyRegisteredDevice`
Jika header `X-Device-ID` tidak sesuai atau perangkat tidak memenuhi syarat, Anda mungkin menerima respons berikut dari endpoint yang dilindungi oleh middleware `verified.device`:

* **Status Kode**: `400 Bad Request`
    ```json
    {
        "message": "Device ID header (X-Device-ID) is missing."
    }
    ```
* **Status Kode**: `403 Forbidden`
    ```json
    {
        "message": "This device is not recognized for your account."
    }
    ```
    atau
    ```json
    {
        "message": "This device is still pending admin approval.", // atau pesan status lainnya
        "device_status": "pending" // atau status lainnya
    }
    ```

---
**Akhir Dokumentasi**
