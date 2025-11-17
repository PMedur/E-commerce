<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Indicator;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer information')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->relationship('customer', 'name')
                            ->preload()
                            ->searchable()
                            ->getOptionLabelFromRecordUsing(fn ($record) =>
                                "{$record->name} {$record->surname} ({$record->email})"
                            )
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('surname')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->unique(Customer::class, 'email')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('mobile_phone')
                                    ->tel()
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('address')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\DateTimePicker::make('date_time')
                            ->required()
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i')
                            ->default(now()),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Product information')
                    ->schema([
                        Forms\Components\Repeater::make('orderDetail')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->relationship('product', 'name')
                                ->preload()
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set, Get $get){
                                    if(!$state)
                                        return;

                                    $product = Product::find($state);

                                    $set('price_per_unit_excluding_vat', round($product->final_price_without_vat, 2));
                                    $set('item_tax_rate', round($product->taxRate->amount * 100, 2));

                                    $quantity = (float) $get('quantity');
                                    $pricePerUnit = $product->final_price_without_vat;

                                    if ($quantity && $pricePerUnit) {
                                        $lineTotal = $quantity * $pricePerUnit;
                                        $set('sum_excluding_vat', round($lineTotal, 2));
                                    }

                                    $orderItems = $get('../../orderDetail') ?? [];
                                    $deliveryAmount = (float) $get('../../delivery_amount_with_vat');

                                    $totalWithoutVat = 0;
                                    $totalWithVat = 0;

                                    foreach($orderItems as $item){
                                        $itemQuantity = (float) ($item['quantity'] ?? 0);
                                        $itemPrice = (float) ($item['price_per_unit_excluding_vat'] ?? 0);
                                        $itemTaxRate = (float) ($item['item_tax_rate'] ?? 0);

                                        if($itemQuantity && $itemPrice){
                                            $lineTotal = $itemQuantity * $itemPrice;
                                            $totalWithoutVat += $lineTotal;
                                            $totalWithVat += $lineTotal * (1 + $itemTaxRate);
                                        }
                                    }

                                    $totalWithVat += $deliveryAmount;

                                    $set('../../total_amount_without_vat', round($totalWithoutVat, 2));
                                    $set('../../total_amount_with_vat', round($totalWithVat, 2));
                                })
                                ->label('Products'),
                            Forms\Components\TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->default(1)
                                ->minValue(1)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get) => static::calculateLineItem($set, $get)),
                            Forms\Components\TextInput::make('price_per_unit_excluding_vat')
                                ->numeric()
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get) => static::calculateLineItem($set, $get))
                                ->suffix('€'),
                            Forms\Components\TextInput::make('item_tax_rate')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->maxValue(100)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, Get $get) => static::calculateLineItem($set, $get))
                                ->formatStateUsing(fn ($state) => round($state * 100, 2))
                                ->dehydrateStateUsing(fn ($state) => round($state / 100, 4))
                                ->helperText('Number between 0 and 100 with maximum 2 decimal point')
                                ->suffix('%')
                                ->label('Tax rate'),
                            Forms\Components\TextInput::make('sum_excluding_vat')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(true)
                                ->suffix('€')
                                ->label('Total for product exluding VAT'),
                        ])
                        ->columns(2)
                        ->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::updateTotals($set, $get))
                        ->deleteAction(
                                    fn (Forms\Components\Actions\Action $action) =>
                                        $action->after(fn (Set $set, Get $get) =>
                                            static::updateTotals($set, $get)
                                        )
                        ),
                    ]),

                Forms\Components\Section::make('Computed Fields')
                ->schema([
                    Forms\Components\TextInput::make('total_amount_with_vat')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(true)
                        ->suffix('€')
                        ->label('Total with VAT'),
                    Forms\Components\TextInput::make('total_amount_without_vat')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(true)
                        ->suffix('€')
                        ->label('Total excluding VAT'),
                    Forms\Components\TextInput::make('delivery_amount_with_vat')
                        ->numeric()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn(Set $set, Get $get) => static::updateTotals($set, $get))
                        ->suffix('€')
                        ->label('Delivery amount with VAT'),
                ])->columns(2),
            ]);
    }

    protected static function calculateLineItem(Set $set, Get $get): void
    {
        $quantity = (float) $get('quantity');
        $pricePerUnit = (float) $get('price_per_unit_excluding_vat');

        if ($quantity && $pricePerUnit) {
            $lineTotal = $quantity * $pricePerUnit;
            $set('sum_excluding_vat', $lineTotal);
        }
    }

    protected static function updateTotals(Set $set, Get $get): void
    {
        $orderItems = $get('orderDetail') ?? [];
        $deliveryAmount = (float) $get('delivery_amount_with_vat');

        $totalWithoutVat = 0;
        $totalWithVat = 0;

        foreach($orderItems as $item){
            $quantity = (float) ($item['quantity'] ?? 0);
            $pricePerUnit = (float) ($item['price_per_unit_excluding_vat'] ?? 0);
            $taxRate = (float) ($item['item_tax_rate'] ?? 0);

            if($quantity && $pricePerUnit){
                $lineTotal = $quantity * $pricePerUnit;
                $totalWithoutVat += $lineTotal;
                $totalWithVat += $lineTotal * (1 + $taxRate);
            }
        }

        $totalWithVat += $deliveryAmount;

        $set('total_amount_without_vat', round($totalWithoutVat, 2));
        $set('total_amount_with_vat', round($totalWithVat, 2));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->numeric()
                    ->sortable()
                    ->searchable(['customers.name', 'customers.surname', 'customers.email'], isIndividual: true)
                    ->formatStateUsing(fn ($record) =>
                        "{$record->customer->name} {$record->customer->surname} ( {$record->customer->email})"
                    ),
                Tables\Columns\TextColumn::make('date_time')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_amount_with_vat')
                    ->money('euro')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_amount_without_vat')
                    ->money('euro')
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery_amount_with_vat')
                    ->money('euro')
                    ->sortable(),
                Tables\Columns\TextColumn::make('orderDetail.product.name')
                    ->numeric()
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->label('Products in sale'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->relationship('customer', 'name')
                    ->label('Customer')
                    ->searchable()
                    ->preload()
                    ->columnSpan(1),
                Filter::make('date_range')
                    ->form([
                        Forms\Components\DateTimePicker::make('from')
                            ->label('From Date')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i'),
                        Forms\Components\DateTimePicker::make('to')
                            ->label('To Date')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->where('date_time', '>=', $date),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $date): Builder => $query->where('date_time', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Created from ' . Carbon::parse($data['from'])->format('d/m/Y H:i'))
                                ->removeField('from');
                        }

                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make('Created to ' . Carbon::parse($data['to'])->format('d/m/Y H:i'))
                                ->removeField('to');
                        }

                        return $indicators;
                    })
                    ->columnSpan(1),
                Filter::make('amount_range')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->numeric()
                            ->label('Min Amount'),
                        Forms\Components\TextInput::make('to')
                            ->numeric()
                            ->label('Max Amount'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $amount): Builder => $query->where('total_amount_with_vat', '>=', $amount),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $amount): Builder => $query->where('total_amount_with_vat', '<=', $amount),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Min amount: ' . $data['from'])
                                ->removeField('from');
                        }

                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make('Max amount: ' . $data['to'])
                                ->removeField('to');
                        }

                        return $indicators;
                    })
                     ->columnSpan(1),
                Filter::make('has_delivery')
                    ->query(fn (Builder $query): Builder => $query->where('delivery_amount_with_vat', '>', 0))
                    ->toggle()
                    ->label('With Delivery')
                     ->columnSpan(1),
                SelectFilter::make('products')
                ->label('Products')
                ->multiple()
                ->relationship('orderDetail.product', 'name')
                ->searchable()
                ->preload(),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(2)
            ->persistFiltersInSession()
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
            'view' => Pages\ViewSale::route('/{record}'),
        ];
    }
}
