<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AttendanceCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection, // $this->collection akan otomatis menggunakan AttendanceResource
            // Anda bisa menambahkan metadata paginasi di sini jika tidak menggunakan respons paginasi standar Laravel
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function with(Request $request): array
    {
        // Jika Anda ingin menambahkan metadata paginasi secara manual
        // Ini biasanya sudah ditangani oleh Laravel jika Anda mengembalikan $query->paginate()
        // dan langsung membungkusnya dengan resource collection.
        // Contoh jika Anda tidak menggunakan respons paginasi standar:
        // return [
        //     'meta' => [
        //         'total' => $this->total(),
        //         'per_page' => $this->perPage(),
        //         'current_page' => $this->currentPage(),
        //         'last_page' => $this->lastPage(),
        //         // ... dan link lainnya
        //     ],
        // ];
        return []; // Biarkan kosong jika menggunakan respons paginasi standar Laravel
    }
}
