<?php

// Samo pravila koja aplikacija koristi; za ostala Laravel uzima engleski tekst.

return [

    'before_or_equal' => 'Polje :attribute mora biti datum pre ili jednak :date.',
    'boolean'         => 'Polje :attribute mora biti da ili ne.',
    'date_format'     => 'Polje :attribute mora biti datum u obliku :format.',
    'decimal'         => 'Polje :attribute ima previše decimala.',
    'email'           => 'Polje :attribute mora biti ispravna email adresa.',
    'in'              => 'Izabrana vrednost za polje :attribute nije dozvoljena.',
    'max'             => [
        'numeric' => 'Polje :attribute ne sme biti veće od :max.',
        'string'  => 'Polje :attribute može imati najviše :max znakova.',
    ],
    'min'             => [
        'numeric' => 'Polje :attribute ne sme biti manje od :min.',
        'string'  => 'Polje :attribute mora imati najmanje :min znakova.',
    ],
    'numeric'         => 'Polje :attribute mora biti broj.',
    'regex'           => 'Polje :attribute nije u ispravnom obliku.',
    'required'        => 'Polje :attribute je obavezno.',
    'string'          => 'Polje :attribute mora biti tekst.',
    'unique'          => 'Vrednost polja :attribute već postoji.',

    'custom' => [
        'sifra' => [
            'regex'  => 'Šifra mora imati tačno 6 cifara.',
            'unique' => 'Artikal sa tom šifrom već postoji.',
        ],
        'kolicina' => [
            'decimal' => 'Količina može imati najviše 3 decimale.',
        ],
        'cena' => [
            'decimal' => 'Cena može imati najviše 2 decimale.',
        ],
        'datum_nabavke' => [
            'before_or_equal' => 'Datum nabavke ne sme biti u budućnosti.',
        ],
    ],

    'attributes' => [
        'email'         => 'email',
        'password'      => 'lozinka',
        'sifra'         => 'šifra',
        'naziv'         => 'naziv',
        'jedinica_mere' => 'jedinica mere',
        'kolicina'      => 'količina',
        'cena'          => 'cena',
        'datum_nabavke' => 'datum nabavke',
        'aktivan'       => 'aktivan',
        'napomena'      => 'napomena',
    ],

];
