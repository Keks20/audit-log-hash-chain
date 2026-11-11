<?php

namespace App\Http\Requests;

use App\Models\Artikal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Provera forme za dodavanje i izmenu artikla.
 * Granice prate kolone u tabeli artikli (varchar(30), decimal(10,3), decimal(12,2)).
 */
class ArtikalRequest extends FormRequest
{
    // Rute su vec iza "auth" middleware-a, ovde nema dodatnih prava
    public function authorize(): bool
    {
        return true;
    }

    // Decimalni zarez ("12,50") prihvatamo kao tacku
    protected function prepareForValidation(): void
    {
        foreach (['kolicina', 'cena'] as $polje) {
            if (is_string($this->input($polje))) {
                $this->merge([$polje => str_replace(',', '.', $this->input($polje))]);
            }
        }
    }

    public function rules(): array
    {
        $pravila = [
            'naziv'         => ['required', 'string', 'max:30'],
            'jedinica_mere' => ['required', Rule::in(Artikal::JEDINICE_MERE)],
            'kolicina'      => [$this->obavezno('kolicina'), 'numeric', 'decimal:0,3', 'min:0', 'max:9999999.999'],
            'cena'          => [$this->obavezno('cena'), 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            // "Danas" po nasem vremenu, ne po UTC (inace bi oko ponoci danasnji datum bio "buducnost")
            'datum_nabavke' => [
                $this->obavezno('datum_nabavke'),
                'date_format:Y-m-d',
                'before_or_equal:' . today(config('magacin.zona_prikaza'))->toDateString(),
            ],
            'aktivan'       => [$this->obavezno('aktivan'), 'boolean'],
            'napomena'      => ['nullable', 'string', 'max:1000'],
        ];

        // Sifra se unosi samo pri dodavanju. Posle je ona identitet artikla
        // (audit log), pa je izmena ne menja ni kad je neko posalje.
        if ($this->isMethod('post')) {
            $pravila['sifra'] = ['required', 'regex:/^\d{6}$/', Rule::unique('artikli', 'sifra')];
        }

        return $pravila;
    }

    /**
     * Novi artikal mora imati sve osim napomene. Pri izmeni polje koje vec
     * ima vrednost ne sme da se isprazni, ali NULL iz starog programa
     * (nepoznata kolicina, "?" za aktivan) sme da ostane: to ne izmisljamo.
     */
    public static function jeObavezno(?Artikal $artikal, string $polje): bool
    {
        return $artikal === null || ! $artikal->exists || $artikal->getRawOriginal($polje) !== null;
    }

    private function obavezno(string $polje): string
    {
        return self::jeObavezno($this->route('artikal'), $polje) ? 'required' : 'nullable';
    }
}
