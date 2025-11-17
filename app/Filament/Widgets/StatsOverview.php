<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $monthlySales = Sale::where('date_time', '>=', $thirtyDaysAgo)
            ->sum('total_amount_with_vat');

        return [
            Stat::make('Total Products', Product::count())
                ->description('Products in inventory')
                ->descriptionIcon('heroicon-o-cube')
                ->color('success'),

            Stat::make('Total Customers', Customer::count())
                ->description('Registered customers')
                ->descriptionIcon('heroicon-o-users')
                ->color('info'),

            Stat::make('Total Sales', Sale::count())
                ->description('Number of orders')
                ->descriptionIcon('heroicon-o-shopping-cart')
                ->color('primary'),

            Stat::make('Monthly Revenue', '€' . $monthlySales, 2)
                ->description('Last 30 days')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('primary'),
        ];
    }
}
