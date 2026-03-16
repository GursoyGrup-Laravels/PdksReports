<?php

namespace App\Filament\Resources\StaffResource\RelationManagers;

use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    /**
     * @return string|null
     */
    public static function getModelLabel(): ?string
    {
        return __('ui.card_reading_report');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('ui.card_reading_reports');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('ui.card_reading_reports');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->paginated([5, 10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label(__('ui.date'))
                    ->badge()
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('day')
                    ->label(__('ui.day'))
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn ($state, $record) => Carbon::parse($record->date)->translatedFormat('l')),
                Tables\Columns\TextColumn::make('first_reading')
                    ->label(__('ui.first_reading'))
                    ->badge()
                    ->color('success')
                    ->Time(),
                Tables\Columns\TextColumn::make('last_reading')
                    ->label(__('ui.last_reading'))
                    ->badge()
                    ->color('success')
                    ->Time(),
                Tables\Columns\TextColumn::make('working_time')
                    ->label(__('ui.working_time'))
                    ->badge()
                    ->color('success'),
            ])
            ->filters([
                Tables\Filters\Filter::make('today')
                    ->label(__('ui.today'))
                    ->query(fn (Builder $query): Builder => $query->whereDate('date', today()))
                    ->default(),
                Tables\Filters\Filter::make('date_range')
                    ->label(__('ui.date_range'))
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('ui.from_date'))
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('to', null))
                            ->maxDate(today()),
                        Forms\Components\DatePicker::make('to')
                            ->label(__('ui.to_date'))
                            ->maxDate(today()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date),
                            );
                    }),
            ])
            ->headerActions([
                Tables\Actions\ExportAction::make()
                    ->exporter(\App\Filament\Exports\ReportExporter::class)
                    ->modifyQueryUsing(fn (Builder $query) =>
                    $query->reorder()->orderBy('date', 'asc')
                    )
                    ->label(__('ui.export'))
                    ->modalHeading(__('ui.export_reports'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()->hasRole('super_admin') || auth()->user()->can('export_reports')),
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }
}
