<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Models\TaxRate;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Product information')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->label('Product Name'),
                    Forms\Components\TextInput::make('sku')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->alphaNum()
                        ->label('SKU'),
                    Forms\Components\TextInput::make('stock_status')
                        ->required()
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->label('Stock Quantity'),
                ])->columns(3),

                Forms\Components\Section::make('Product images')
                ->schema([
                    Forms\Components\FileUpload::make('main_img')
                        ->label('Main Image')
                        ->image()
                        ->imageEditor()
                        ->maxSize(2048)
                        ->disk('public')
                        ->visibility('private')
                        ->directory('products/main')
                        ->downloadable()
                        ->openable()
                        ->previewable(),
                    Forms\Components\FileUpload::make('picture_galery')
                        ->label('Gallery Images')
                        ->image()
                        ->imageEditor()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->maxFiles(10)
                        ->maxSize(2048)
                        ->disk('public')
                        ->visibility('private')
                        ->directory('products/gallery')
                        ->downloadable()
                        ->openable()
                        ->previewable(),
                ])->columns(2),

                Forms\Components\Section::make('Product pricing')
                ->schema([
                    Forms\Components\TextInput::make('purchase_price_eur')
                        ->required()
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('€')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::updateComputedFields($set, $get))
                        ->label('Purchase Price'),
                    Forms\Components\TextInput::make('selling_price_eur')
                        ->required()
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('€')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::updateComputedFields($set, $get))
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                $purchasePrice = (float) $get('purchase_price_eur');
                                if ($value < ($purchasePrice * 1.04)) {
                                    $fail('Selling price must be at least 4% higher than purchase price.');
                                }
                            },
                        ])
                        ->label('Selling Price'),
                    Forms\Components\Select::make('tax_rate_id')
                        ->relationship('taxRate', 'name')
                        ->preload()
                        ->searchable()
                        ->getOptionLabelFromRecordUsing(function ($record) {
                            $percentage = $record->amount * 100;
                            return "{$record->name} ({$percentage}%)";
                        })
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::updateComputedFields($set, $get))
                        ->label('Tax rate'),
                    Forms\Components\TextInput::make('discount_percentage')
                        ->required()
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::updateComputedFields($set, $get))
                        ->helperText('Number between 0 and 100 with maximum 2 decimal point')
                        ->label('Discount')
                        ->suffix('%'),
                ])->columns(2),

                Forms\Components\Section::make('Product computed fields')
                ->schema([
                    Forms\Components\TextInput::make('final_price_without_vat')
                        ->disabled()
                        ->dehydrated(false)
                        ->numeric()
                        ->afterStateHydrated(function ($component, $record) {
                            if($record){
                                $component->state(round($record->final_price_without_vat, 2));
                            }
                        })
                        ->label('Final price without VAT')
                        ->suffix('€'),
                    Forms\Components\TextInput::make('final_price_with_vat')
                        ->disabled()
                        ->dehydrated(false)
                        ->numeric()
                        ->afterStateHydrated(function ($component, $record) {
                            if($record){
                                $component->state(round($record->final_price_with_vat, 2));
                            }
                        })
                        ->label('Final price with VAT')
                        ->suffix('€'),
                    Forms\Components\TextInput::make('margin_percentage')
                        ->disabled()
                        ->dehydrated(false)
                        ->numeric()
                        ->afterStateHydrated(function ($component, $record) {
                            if($record){
                                $component->state(round($record->margin_percentage, 2));
                            }
                        })
                        ->suffix('%'),
                ])->columns(2),
            ]);
    }

    protected static function updateComputedFields(Set $set, Get $get): void
    {
        $sellingPrice = (float) $get('selling_price_eur');
        $purchasePrice = (float) $get('purchase_price_eur');
        $discountPercentage = (float) $get('discount_percentage');
        $taxRateId = $get('tax_rate_id');

        $finalPriceWithoutVat = $sellingPrice * (1 - ($discountPercentage / 100));
        $set('final_price_without_vat', round($finalPriceWithoutVat, 2));

        if($taxRateId){
            $taxRate = TaxRate::find($taxRateId);
            if($taxRate){
                $finalPriceWithVat = $finalPriceWithoutVat * (1 + $taxRate->amount);
                $set('final_price_with_vat', round($finalPriceWithVat, 2));
            }
        }

        if($purchasePrice > 0 && $sellingPrice > 0){
            $marginPercentage = (($sellingPrice - $purchasePrice) / $sellingPrice) * 100;
            $set('margin_percentage', round($marginPercentage, 2));
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(isIndividual: true),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(isIndividual: true),
                Tables\Columns\ImageColumn::make('main_img')
                    ->label('Main image')
                    ->width(50)
                    ->height(50),
                Tables\Columns\ImageColumn::make('picture_galery')
                    ->label('Image Galery')
                    ->width(50)
                    ->height(50),
                Tables\Columns\TextColumn::make('stock_status')
                    ->numeric()
                    ->searchable(isIndividual: true),
                Tables\Columns\TextColumn::make('purchase_price_eur')
                    ->money('euro')
                    ->sortable(),
                Tables\Columns\TextColumn::make('selling_price_eur')
                    ->money('euro')
                    ->sortable(),
                Tables\Columns\TextColumn::make('taxRate.name')
                    ->numeric()
                    ->formatStateUsing(fn ($record) => $record->taxRate->amount * 100 . "%")
                    ->sortable(),
                Tables\Columns\TextColumn::make('discount_percentage')
                    ->numeric()
                    ->sortable()
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('final_price_without_vat')
                    ->money('euro'),
                Tables\Columns\TextColumn::make('final_price_with_vat')
                    ->money('euro'),
                Tables\Columns\TextColumn::make('margin_percentage')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('units_sold_this_year')
                    ->numeric(),
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
                SelectFilter::make('tax_rate_id')
                    ->relationship('taxRate', 'name')
                    ->label('Tax Rate')
                    ->searchable()
                    ->preload()
                    ->getOptionLabelFromRecordUsing(function ($record) {
                            $percentage = $record->amount * 100;
                            return "{$record->name} ({$percentage}%)";
                    }),
                Filter::make('in_stock')
                    ->query(fn (Builder $query): Builder => $query->where('stock_status', '>', 0))
                    ->toggle(),
                Filter::make('has_discount')
                    ->query(fn (Builder $query): Builder => $query->where('discount_percentage', '>', 0))
                    ->toggle(),
                Filter::make('selling_price_range')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->numeric()
                            ->suffix('€')
                            ->label('Min selling price'),
                        Forms\Components\TextInput::make('to')
                            ->numeric()
                            ->suffix('€')
                            ->label('Max selling price'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $amount): Builder => $query->where('selling_price_eur', '>=', $amount),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $amount): Builder => $query->where('selling_price_eur', '<=', $amount),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Min selling price: ' . $data['from']. '€')
                                ->removeField('from');
                        }

                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make('Max selling price: ' . $data['to']. '€')
                                ->removeField('to');
                        }

                        return $indicators;
                    }),
                Filter::make('purchase_price_range')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->numeric()
                            ->suffix('€')
                            ->label('Min purchase price'),
                        Forms\Components\TextInput::make('to')
                            ->numeric()
                            ->suffix('€')
                            ->label('Max purchase price'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $amount): Builder => $query->where('purchase_price_eur', '>=', $amount),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $amount): Builder => $query->where('purchase_price_eur', '<=', $amount),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Min purchase price: ' . $data['from'])
                                ->removeField('from');
                        }

                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make('Max purchase price: ' . $data['to'])
                                ->removeField('to');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                ->requiresConfirmation()
                    ->before(function (Product $record, Tables\Actions\DeleteAction $action) {
                        if ($record->orderDetail()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title('Cannot delete product')
                                ->body('This product is used by ' . $record->orderDetail()->count() . ' order(s). Please reassign them first.')
                                ->send();

                            $action->cancel();
                        }
                    })
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Successful deletion')
                            ->body('The product has been deleted successfully.'),
                ),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
            'view' => Pages\ViewProduct::route('/{record}'),
        ];
    }
}
