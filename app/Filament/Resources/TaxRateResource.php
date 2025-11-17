<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxRateResource\Pages;
use App\Models\TaxRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;


class TaxRateResource extends Resource
{
    protected static ?string $model = TaxRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Tax rate information')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: True),
                    Forms\Components\TextInput::make('amount')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.1)
                        ->default(0)
                        ->formatStateUsing(fn ($state) => round($state * 100, 2))
                        ->dehydrateStateUsing(fn ($state) => round($state / 100, 4))
                        ->suffix('%')
                        ->label('Tax rate amount')
                        ->helperText('Number between 0 and 100 with maximum 2 decimal point'),
                ])
                ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => round($state * 100, 2))
                    ->suffix('%')
                    ->sortable()
                    ->label('Tax rate amount'),
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
                Filter::make('amount_range')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->numeric()
                            ->step(0.1)
                            ->suffix('%')
                            ->label('Min percentage'),
                        Forms\Components\TextInput::make('to')
                            ->numeric()
                            ->step(0.1)
                            ->suffix('%')
                            ->label('Max percentage'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $amount): Builder => $query->where('amount', '>=', round($amount / 100, 4)),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $amount): Builder => $query->where('amount', '<=', round($amount / 100, 4)),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Min percentage: ' . $data['from']. '%')
                                ->removeField('from');
                        }

                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make('Max percentage: ' . $data['to']. '%')
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
                    ->before(function (TaxRate $record, Tables\Actions\DeleteAction $action) {
                        if ($record->products()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title('Cannot delete tax rate')
                                ->body('This tax rate is used by ' . $record->products()->count() . ' product(s). Please reassign them first.')
                                ->send();

                            $action->cancel();
                        }
                    })
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Successful deletion')
                            ->body('The tax rate has been deleted successfully.'),
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
            'index' => Pages\ListTaxRates::route('/'),
            'create' => Pages\CreateTaxRate::route('/create'),
            'edit' => Pages\EditTaxRate::route('/{record}/edit'),
            'view' => Pages\ViewTaxRate::route('/{record}'),
        ];
    }
}
