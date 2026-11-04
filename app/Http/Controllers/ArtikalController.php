<?php

namespace App\Http\Controllers;

use App\Models\Artikal;

class ArtikalController extends Controller
{
    /**
     * Lista svih artikala, sortirano po sifri.
     */
    public function index()
    {
        $artikli = Artikal::orderBy('sifra')->get();

        return view('artikli.index', compact('artikli'));
    }
}