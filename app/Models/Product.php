<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'main_img',
        'picture_galery',
        'stock_status',
        'purchase_price_eur',
        'selling_price_eur',
        'tax_rate_id',
        'discount_percentage'
    ];

    protected function casts(): array
    {
        return [
            'picture_galery' => 'array',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function orderDetail(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function finalPriceWithoutVAT(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->selling_price_eur * (1 - ($this->discount_percentage / 100))
        );
    }

    public function finalPriceWithVAT(): Attribute
    {
        return Attribute::make(
            get: function() {
                $priceWithoutVAT = $this->final_price_without_vat;
                $taxRate = $this->taxRate->amount ?? 0;
                return $priceWithoutVAT * (1 + $taxRate);
            }
        );
    }

    public function marginPercentage(): Attribute
    {
        return Attribute::make(
            get: fn() => (($this->selling_price_eur - $this->purchase_price_eur) / $this->selling_price_eur) * 100
        );
    }

    public function unitsSoldThisYear(): Attribute
    {
        return Attribute::make(
            get: function() {
                $currYear = now()->year;
                $unitsSold = $this->orderDetail()->whereHas('sale', function($query) use ($currYear) {
                    $query->whereYear('date_time', $currYear);
                })->sum('quantity');

                return $unitsSold;
            }
        );
    }
}
