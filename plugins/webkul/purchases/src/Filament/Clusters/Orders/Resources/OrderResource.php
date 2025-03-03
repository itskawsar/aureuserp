<?php

namespace Webkul\Purchase\Filament\Clusters\Orders\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\TypeTaxUse;
use Webkul\Account\Filament\Resources\IncoTermResource;
use Webkul\Account\Models\Partner;
use Webkul\Account\Services\TaxService;
use Webkul\Field\Filament\Forms\Components\ProgressStepper;
use Webkul\Field\Filament\Traits\HasCustomFields;
use Webkul\Product\Models\Packaging;
use Webkul\Purchase\Enums;
use Webkul\Purchase\Livewire\Summary;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\Models\Product;
use Webkul\Purchase\Settings;
use Webkul\Purchase\Settings\OrderSettings;
use Webkul\Support\Models\UOM;

class OrderResource extends Resource
{
    use HasCustomFields;

    protected static ?string $model = Order::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                ProgressStepper::make('state')
                    ->hiddenLabel()
                    ->inline()
                    ->options(function ($record) {
                        $options = Enums\OrderState::options();

                        if ($record && $record->state !== Enums\OrderState::CANCELED) {
                            unset($options[Enums\OrderState::CANCELED->value]);
                        }

                        if ($record && $record->state !== Enums\OrderState::DONE) {
                            unset($options[Enums\OrderState::DONE->value]);
                        }

                        return $options;
                    })
                    ->default(Enums\OrderState::DRAFT)
                    ->disabled(),
                Forms\Components\Section::make(__('purchases::filament/clusters/orders/resources/order.form.sections.general.title'))
                    ->schema([
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Select::make('partner_id')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.vendor'))
                                    ->relationship(
                                        'partner',
                                        'name',
                                        fn($query) => $query->where('sub_type', 'supplier')
                                    )
                                    ->searchable()
                                    ->required()
                                    ->preload()
                                    ->createOptionForm(fn(Form $form) => VendorResource::form($form))
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if ($state) {
                                            $vendor = Partner::find($state);

                                            $set('payment_term_id', $vendor->property_supplier_payment_term_id);
                                        }
                                    })
                                    ->live()
                                    ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                                Forms\Components\TextInput::make('partner_reference')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.vendor-reference'))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.vendor-reference-tooltip')),
                                Forms\Components\Select::make('requisition_id')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.agreement'))
                                    ->relationship('requisition', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->visible(fn(OrderSettings $setting): bool => $setting->enable_purchase_agreements),
                                Forms\Components\Select::make('currency_id')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.currency'))
                                    ->relationship('currency', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->default(Auth::user()->defaultCompany?->currency_id)
                                    ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                            ]),

                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\DateTimePicker::make('approved_at')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.confirmation-date'))
                                    ->native(false)
                                    ->suffixIcon('heroicon-o-calendar')
                                    ->default(now())
                                    ->disabled()
                                    ->visible(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                                Forms\Components\DateTimePicker::make('ordered_at')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.order-deadline'))
                                    ->native(false)
                                    ->required()
                                    ->suffixIcon('heroicon-o-calendar')
                                    ->default(now())
                                    ->hidden(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                                Forms\Components\DateTimePicker::make('planned_at')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.expected-arrival'))
                                    ->native(false)
                                    ->suffixIcon('heroicon-o-calendar')
                                    ->hint('Test')
                                    ->hint(fn($record): string => $record && $record->mail_reminder_confirmed ? __('purchases::filament/clusters/orders/resources/order.form.sections.general.fields.confirmed-by-vendor') : '')
                                    ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT, Enums\OrderState::PURCHASE])),
                            ]),
                    ])
                    ->columns(2),

                Forms\Components\Tabs::make()
                    ->schema([
                        Forms\Components\Tabs\Tab::make(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.title'))
                            ->schema([
                                static::getProductRepeater(),
                                Forms\Components\Livewire::make(Summary::class, function (Forms\Get $get) {
                                    return [
                                        'products' => $get('products'),
                                    ];
                                })
                                    ->live()
                                    ->reactive(),
                            ]),

                        Forms\Components\Tabs\Tab::make(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.title'))
                            ->schema(static::mergeCustomFormFields([
                                Forms\Components\Group::make()
                                    ->schema([
                                        Forms\Components\Select::make('user_id')
                                            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.buyer'))
                                            ->relationship('user', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->default(Auth::id())
                                            ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT, Enums\OrderState::PURCHASE])),
                                        Forms\Components\Select::make('company_id')
                                            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.company'))
                                            ->relationship('company', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->default(Auth::user()->default_company_id)
                                            ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                                        Forms\Components\TextInput::make('reference')
                                            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.source-document')),
                                        Forms\Components\Select::make('incoterm_id')
                                            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.incoterm'))
                                            ->relationship('incoterm', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->createOptionForm(fn(Form $form) => IncoTermResource::form($form))
                                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: __('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.incoterm-tooltip'))
                                            ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT, Enums\OrderState::PURCHASE])),
                                        Forms\Components\TextInput::make('reference')
                                            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.incoterm-location'))
                                            ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT, Enums\OrderState::PURCHASE])),
                                    ]),

                                Forms\Components\Group::make()
                                    ->schema([
                                        Forms\Components\Select::make('payment_term_id')
                                            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.additional.fields.payment-term'))
                                            ->relationship('paymentTerm', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->disabled(fn($record): bool => $record && ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT, Enums\OrderState::PURCHASE])),
                                    ]),
                            ]))
                            ->columns(2),

                        Forms\Components\Tabs\Tab::make(__('purchases::filament/clusters/orders/resources/order.form.tabs.terms.title'))
                            ->schema([
                                Forms\Components\RichEditor::make('description')
                                    ->hiddenLabel(),
                            ]),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::mergeCustomTableColumns([
                Tables\Columns\IconColumn::make('priority')
                    ->label('')
                    ->icon(fn(Order $record): string => $record->priority ? 'heroicon-s-star' : 'heroicon-o-star')
                    ->color(fn(Order $record): string => $record->priority ? 'warning' : 'gray')
                    ->action(function (Order $record): void {
                        $record->update([
                            'priority' => ! $record->priority,
                        ]);
                    }),
                Tables\Columns\TextColumn::make('partner_reference')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.vendor-reference'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.reference'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('partner.name')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.vendor'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.company'))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.buyer'))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ordered_at')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.order-deadline'))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('origin')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.source-document'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('untaxed_amount')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.untaxed-amount'))
                    ->sortable()
                    ->money(fn(Order $record) => $record->currency->code)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.total-amount'))
                    ->sortable()
                    ->money(fn(Order $record) => $record->currency->code)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('invoice_status')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.billing-status'))
                    ->sortable()
                    ->badge()
                    ->toggleable(),
            ]))
            ->groups([
                Tables\Grouping\Group::make('partner.name')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.groups.vendor')),
                Tables\Grouping\Group::make('user.name')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.groups.buyer')),
                Tables\Grouping\Group::make('state')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.groups.state')),
                Tables\Grouping\Group::make('created_at')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.groups.created-at'))
                    ->collapsible(),
                Tables\Grouping\Group::make('updated_at')
                    ->label(__('purchases::filament/clusters/orders/resources/order.table.groups.updated-at'))
                    ->date()
                    ->collapsible(),
            ])
            ->filters([
                Tables\Filters\QueryBuilder::make()
                    ->constraints(collect(static::mergeCustomTableQueryBuilderConstraints([
                        Tables\Filters\QueryBuilder\Constraints\SelectConstraint::make('state')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.status'))
                            ->multiple()
                            ->options(Enums\OrderState::class)
                            ->icon('heroicon-o-bars-2'),
                        Tables\Filters\QueryBuilder\Constraints\TextConstraint::make('partner_reference')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.vendor-reference'))
                            ->icon('heroicon-o-identification'),
                        Tables\Filters\QueryBuilder\Constraints\TextConstraint::make('name')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.reference'))
                            ->icon('heroicon-o-identification'),
                        Tables\Filters\QueryBuilder\Constraints\NumberConstraint::make('untaxed_amount')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.untaxed-amount')),
                        Tables\Filters\QueryBuilder\Constraints\NumberConstraint::make('total_amount')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.total-amount')),
                        Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint::make('partner')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.vendor'))
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            )
                            ->icon('heroicon-o-user'),
                        Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint::make('user')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.buyer'))
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            )
                            ->icon('heroicon-o-user'),
                        Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint::make('company')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.company'))
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            )
                            ->icon('heroicon-o-building-office'),
                        Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint::make('paymentTerm')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.payment-term'))
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            )
                            ->icon('heroicon-o-currency-dollar'),
                        Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint::make('incoterm')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.incoterm'))
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            )
                            ->icon('heroicon-o-globe-alt'),
                        Tables\Filters\QueryBuilder\Constraints\DateConstraint::make('ordered_at')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.order-deadline')),
                        Tables\Filters\QueryBuilder\Constraints\DateConstraint::make('created_at')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.created-at')),
                        Tables\Filters\QueryBuilder\Constraints\DateConstraint::make('updated_at')
                            ->label(__('purchases::filament/clusters/orders/resources/order.table.filters.updated-at')),
                    ]))->filter()->values()->all()),
            ], layout: \Filament\Tables\Enums\FiltersLayout::Modal)
            ->filtersTriggerAction(
                fn(Tables\Actions\Action $action) => $action
                    ->slideOver(),
            )
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('purchases::filament/clusters/orders/resources/order.table.actions.delete.notification.title'))
                                ->body(__('purchases::filament/clusters/orders/resources/order.table.actions.delete.notification.body')),
                        ),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('purchases::filament/clusters/orders/resources/order.table.bulk-actions.delete.notification.title'))
                            ->body(__('purchases::filament/clusters/orders/resources/order.table.bulk-actions.delete.notification.body')),
                    ),
            ])
            ->checkIfRecordIsSelectableUsing(
                fn(Model $record): bool => static::can('delete', $record) && $record->state !== Enums\RequisitionState::CLOSED,
            );
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make()
                    ->schema([
                        Infolists\Components\TextEntry::make('state')
                            ->badge(),
                    ])
                    ->compact(),

                Infolists\Components\Section::make(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.title'))
                    ->schema([
                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\Group::make([
                                    Infolists\Components\TextEntry::make('partner.name')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.vendor'))
                                        ->icon('heroicon-o-user-group'),
                                    Infolists\Components\TextEntry::make('partner_reference')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.vendor-reference'))
                                        ->icon('heroicon-o-document-text')
                                        ->placeholder('—'),
                                    Infolists\Components\TextEntry::make('requisition.name')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.agreement'))
                                        ->placeholder('—')
                                        ->icon('heroicon-o-document-check')
                                        ->visible(fn(OrderSettings $setting): bool => $setting->enable_purchase_agreements),
                                    Infolists\Components\TextEntry::make('currency.name')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.currency'))
                                        ->icon('heroicon-o-currency-dollar'),
                                ]),

                                Infolists\Components\Group::make([
                                    Infolists\Components\TextEntry::make('approved_at')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.confirmation-date'))
                                        ->dateTime()
                                        ->icon('heroicon-o-calendar')
                                        ->visible(fn($record): bool => ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                                    Infolists\Components\TextEntry::make('ordered_at')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.order-deadline'))
                                        ->dateTime()
                                        ->icon('heroicon-o-calendar')
                                        ->hidden(fn($record): bool => ! in_array($record?->state, [Enums\OrderState::DRAFT, Enums\OrderState::SENT])),
                                    Infolists\Components\TextEntry::make('planned_at')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.expected-arrival'))
                                        ->dateTime()
                                        ->icon('heroicon-o-calendar')
                                        ->hintColor('success')
                                        ->hint(fn($record): string => $record->mail_reminder_confirmed ? __('purchases::filament/clusters/orders/resources/order.infolist.sections.general.entries.confirmed-by-vendor') : ''),
                                ]),
                            ]),
                    ]),

                Infolists\Components\Tabs::make('Tabs')
                    ->tabs([
                        Infolists\Components\Tabs\Tab::make(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.title'))
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('lines')
                                    ->hiddenLabel()
                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.title'))
                                    ->schema([
                                        Infolists\Components\Grid::make(4)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('product.name')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.product'))
                                                    ->icon('heroicon-o-cube'),
                                                Infolists\Components\TextEntry::make('planned_at')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.expected-arrival'))
                                                    ->dateTime()
                                                    ->icon('heroicon-o-calendar'),
                                                Infolists\Components\TextEntry::make('product_qty')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.quantity'))
                                                    ->icon('heroicon-o-calculator'),
                                                Infolists\Components\TextEntry::make('qty_received')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.received'))
                                                    ->visible(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::PURCHASE, Enums\OrderState::DONE]))
                                                    ->icon('heroicon-o-calculator'),
                                                Infolists\Components\TextEntry::make('qty_invoiced')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.billed'))
                                                    ->visible(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::PURCHASE, Enums\OrderState::DONE]))
                                                    ->icon('heroicon-o-calculator'),
                                                Infolists\Components\TextEntry::make('uom.name')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.unit'))
                                                    ->icon('heroicon-o-beaker')
                                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_uom),
                                                Infolists\Components\TextEntry::make('product_packaging_qty')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.packaging-qty'))
                                                    ->icon('heroicon-o-calculator')
                                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_packagings),
                                                Infolists\Components\TextEntry::make('productPackaging.name')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.packaging'))
                                                    ->icon('heroicon-o-gift')
                                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_packagings),
                                                Infolists\Components\TextEntry::make('price_unit')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.unit-price'))
                                                    ->money(fn($record) => $record->order->currency->code),
                                                Infolists\Components\TextEntry::make('taxes.name')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.taxes'))
                                                    ->bulleted(),
                                                Infolists\Components\TextEntry::make('discount')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.discount-percentage'))
                                                    ->suffix('%'),
                                                Infolists\Components\TextEntry::make('price_subtotal')
                                                    ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.products.repeater.products.entries.amount'))
                                                    ->money(fn($record) => $record->order->currency->code),
                                            ]),
                                    ])
                                    ->columnSpanFull(),

                                Infolists\Components\Group::make([
                                    Infolists\Components\TextEntry::make('untaxed_amount')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.untaxed-amount'))
                                        ->money(fn(Order $record) => $record->currency->code),
                                    Infolists\Components\TextEntry::make('tax_amount')
                                        ->label('Tax Amount')
                                        ->money(fn(Order $record) => $record->currency->code),
                                    Infolists\Components\TextEntry::make('total_amount')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.total-amount'))
                                        ->money(fn(Order $record) => $record->currency->code),
                                    Infolists\Components\TextEntry::make('invoice_status')
                                        ->label(__('purchases::filament/clusters/orders/resources/order.table.columns.billing-status'))
                                        ->badge(),
                                ])
                                    ->columnSpanFull()
                                    ->columns(4),
                            ]),

                        Infolists\Components\Tabs\Tab::make(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.title'))
                            ->schema([
                                Infolists\Components\Grid::make(2)
                                    ->schema([
                                        Infolists\Components\Group::make([
                                            Infolists\Components\TextEntry::make('user.name')
                                                ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.entries.buyer'))
                                                ->placeholder('—'),
                                            Infolists\Components\TextEntry::make('company.name')
                                                ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.entries.company'))
                                                ->placeholder('—'),
                                            Infolists\Components\TextEntry::make('reference')
                                                ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.entries.source-document'))
                                                ->placeholder('—'),
                                            Infolists\Components\TextEntry::make('incoterm.name')
                                                ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.entries.incoterm'))
                                                ->icon('heroicon-o-question-mark-circle')
                                                ->placeholder('—')
                                                ->tooltip(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.entries.incoterm-tooltip')),
                                        ]),

                                        Infolists\Components\Group::make([
                                            Infolists\Components\TextEntry::make('paymentTerm.name')
                                                ->label(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.additional.entries.payment-term'))
                                                ->placeholder('—'),
                                        ]),
                                    ]),
                            ]),

                        Infolists\Components\Tabs\Tab::make(__('purchases::filament/clusters/orders/resources/order.infolist.tabs.terms.title'))
                            ->schema([
                                Infolists\Components\TextEntry::make('description')
                                    ->hiddenLabel()
                                    ->markdown()
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    public static function getProductRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('products')
            ->relationship('lines')
            ->hiddenLabel()
            ->live()
            ->reactive()
            ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.title'))
            ->addActionLabel(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.add-product-line'))
            ->collapsible()
            ->defaultItems(0)
            ->itemLabel(fn(array $state): ?string => $state['name'] ?? null)
            ->deleteAction(fn(Forms\Components\Actions\Action $action) => $action->requiresConfirmation())
            ->deletable(fn($record): bool => ! in_array($record?->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED]))
            ->addable(fn($record): bool => ! in_array($record?->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED]))
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.product'))
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        static::afterProductUpdated($set, $get);
                                    })
                                    ->required()
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::SENT, Enums\OrderState::PURCHASE, Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\DateTimePicker::make('planned_at')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.expected-arrival'))
                                    ->native(false)
                                    ->suffixIcon('heroicon-o-calendar')
                                    ->required()
                                    ->default(now())
                                    ->default(function (Forms\Get $get, Forms\Set $set) {
                                        if (empty($get('../../planned_at'))) {
                                            $set('../../planned_at', now());
                                        }

                                        return now();
                                    })
                                    ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                                        $set('../../planned_at', $state);
                                    })
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('product_qty')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.quantity'))
                                    ->required()
                                    ->default(1)
                                    ->numeric()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        static::afterProductQtyUpdated($set, $get);
                                    })
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('qty_received')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.received'))
                                    ->required()
                                    ->default(0)
                                    ->numeric()
                                    ->visible(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::PURCHASE, Enums\OrderState::DONE]))
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('qty_invoiced')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.billed'))
                                    ->default(0)
                                    ->numeric()
                                    ->visible(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::PURCHASE, Enums\OrderState::DONE]))
                                    ->disabled(),
                                Forms\Components\Select::make('uom_id')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.unit'))
                                    ->relationship(
                                        'uom',
                                        'name',
                                        fn($query) => $query->where('category_id', 1)->orderBy('id'),
                                    )
                                    ->required()
                                    ->live()
                                    ->selectablePlaceholder(false)
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        static::afterUOMUpdated($set, $get);
                                    })
                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_uom)
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::PURCHASE, Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('product_packaging_qty')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.packaging-qty'))
                                    ->live()
                                    ->numeric()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        static::afterProductPackagingQtyUpdated($set, $get);
                                    })
                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_packagings)
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\Select::make('product_packaging_id')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.packaging'))
                                    ->relationship(
                                        'productPackaging',
                                        'name',
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        static::afterProductPackagingUpdated($set, $get);
                                    })
                                    ->visible(fn(Settings\ProductSettings $settings) => $settings->enable_packagings)
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('price_unit')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.unit-price'))
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        self::calculateLineTotals($set, $get);
                                    })
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\Select::make('taxes')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.taxes'))
                                    ->relationship(
                                        'taxes',
                                        'name',
                                        function (Builder $query) {
                                            return $query->where('type_tax_use', TypeTaxUse::PURCHASE->value);
                                        },
                                    )
                                    ->searchable()
                                    ->multiple()
                                    ->preload()
                                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, $state) {
                                        self::calculateLineTotals($set, $get);
                                    })
                                    ->live()
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('discount')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.discount-percentage'))
                                    ->numeric()
                                    ->default(0)
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get) {
                                        self::calculateLineTotals($set, $get);
                                    })
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\TextInput::make('price_subtotal')
                                    ->label(__('purchases::filament/clusters/orders/resources/order.form.tabs.products.repeater.products.fields.amount'))
                                    ->default(0)
                                    ->readOnly()
                                    ->disabled(fn($record): bool => in_array($record?->order->state, [Enums\OrderState::DONE, Enums\OrderState::CANCELED])),
                                Forms\Components\Hidden::make('product_uom_qty')
                                    ->default(0),
                                Forms\Components\Hidden::make('price_tax')
                                    ->default(0),
                                Forms\Components\Hidden::make('price_total')
                                    ->default(0),
                            ]),
                    ])
                    ->columns(2),
            ])
            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $record) {
                $product = Product::find($data['product_id']);

                $data = array_merge($data, [
                    'name'                => $product->name,
                    'state'               => $record->state->value,
                    'qty_received_method' => 'manual',
                    'uom_id'              => $data['uom_id'] ?? $product->uom_id,
                    'currency_id'         => $record->currency_id,
                    'partner_id'          => $record->partner_id,
                    'creator_id'          => Auth::id(),
                    'company_id'          => Auth::user()->default_company_id,
                ]);

                return $data;
            });
    }

    private static function afterProductUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        $product = Product::find($get('product_id'));

        $set('uom_id', $product->uom_id);

        $priceUnit = static::calculateUnitPrice($get('uom_id'), $product->cost ?? $product->price);

        $set('price_unit', round($priceUnit, 2));

        $set('taxes', $product->productTaxes->pluck('id')->toArray());

        $uomQuantity = static::calculateUnitQuantity($get('uom_id'), $get('product_qty'));

        $set('product_uom_qty', round($uomQuantity, 2));

        $packaging = static::getBestPackaging($get('product_id'), round($uomQuantity, 2));

        $set('product_packaging_id', $packaging['packaging_id'] ?? null);

        $set('product_packaging_qty', $packaging['packaging_qty'] ?? null);

        self::calculateLineTotals($set, $get);
    }

    private static function afterProductQtyUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        $uomQuantity = static::calculateUnitQuantity($get('uom_id'), $get('product_qty'));

        $set('product_uom_qty', round($uomQuantity, 2));

        $packaging = static::getBestPackaging($get('product_id'), $uomQuantity);

        $set('product_packaging_id', $packaging['packaging_id'] ?? null);

        $set('product_packaging_qty', $packaging['packaging_qty'] ?? null);

        self::calculateLineTotals($set, $get);
    }

    private static function afterUOMUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        $uomQuantity = static::calculateUnitQuantity($get('uom_id'), $get('product_qty'));

        $set('product_uom_qty', round($uomQuantity, 2));

        $packaging = static::getBestPackaging($get('product_id'), $uomQuantity);

        $set('product_packaging_id', $packaging['packaging_id'] ?? null);

        $set('product_packaging_qty', $packaging['packaging_qty'] ?? null);

        $product = Product::find($get('product_id'));

        $priceUnit = static::calculateUnitPrice($get('uom_id'), $product->cost ?? $product->price);

        $set('price_unit', round($priceUnit, 2));

        self::calculateLineTotals($set, $get);
    }

    private static function afterProductPackagingQtyUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        if ($get('product_packaging_id')) {
            $packaging = Packaging::find($get('product_packaging_id'));

            $packagingQty = floatval($get('product_packaging_qty') ?? 0);

            $productUOMQty = $packagingQty * $packaging->qty;

            $set('product_uom_qty', round($productUOMQty, 2));

            $uom = Uom::find($get('uom_id'));

            $productQty = $uom ? $productUOMQty * $uom->factor : $productUOMQty;

            $set('product_qty', round($productQty, 2));
        }

        self::calculateLineTotals($set, $get);
    }

    private static function afterProductPackagingUpdated(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            return;
        }

        if ($get('product_packaging_id')) {
            $packaging = Packaging::find($get('product_packaging_id'));

            $productUOMQty = $get('product_uom_qty') ?: 1;

            if ($packaging) {
                $packagingQty = $productUOMQty / $packaging->qty;

                $set('product_packaging_qty', $packagingQty);
            }
        } else {
            $set('product_packaging_qty', null);
        }

        self::calculateLineTotals($set, $get);
    }

    private static function calculateUnitQuantity($uomId, $quantity)
    {
        if (! $uomId) {
            return $quantity;
        }

        $uom = Uom::find($uomId);

        return (float) ($quantity ?? 0) / $uom->factor;
    }

    private static function calculateUnitPrice($uomId, $price)
    {
        if (! $uomId) {
            return $price;
        }

        $uom = Uom::find($uomId);

        return (float) ($price / $uom->factor);
    }

    private static function getBestPackaging($productId, $quantity)
    {
        $packagings = Packaging::where('product_id', $productId)
            ->orderByDesc('qty')
            ->get();

        foreach ($packagings as $packaging) {
            if ($quantity && $quantity % $packaging->qty == 0) {
                return [
                    'packaging_id'  => $packaging->id,
                    'packaging_qty' => round($quantity / $packaging->qty, 2),
                ];
            }
        }

        return null;
    }

    private static function calculateLineTotals(Forms\Set $set, Forms\Get $get): void
    {
        if (! $get('product_id')) {
            $set('price_unit', 0);

            $set('discount', 0);

            $set('price_tax', 0);

            $set('price_subtotal', 0);

            $set('price_total', 0);

            return;
        }

        $priceUnit = floatval($get('price_unit'));

        $quantity = floatval($get('product_qty') ?? 1);

        $subTotal = $priceUnit * $quantity;

        $discountValue = floatval($get('discount') ?? 0);

        if ($discountValue > 0) {
            $discountAmount = $subTotal * ($discountValue / 100);

            $subTotal = $subTotal - $discountAmount;
        }

        $taxIds = $get('taxes') ?? [];

        [$subTotal, $taxAmount] = app(TaxService::class)->collectionTaxes($taxIds, $subTotal, $quantity);

        $set('price_subtotal', round($subTotal, 4));

        $set('price_tax', $taxAmount);

        $set('price_total', $subTotal + $taxAmount);
    }

    public static function collectTotals(Order $record): void
    {
        $record->untaxed_amount = 0;
        $record->tax_amount = 0;
        $record->total_amount = 0;
        $record->total_cc_amount = 0;

        foreach ($record->lines as $line) {
            $line = static::collectLineTotals($line);

            $record->untaxed_amount += $line->price_subtotal;
            $record->tax_amount += $line->price_tax;
            $record->total_amount += $line->price_total;
            $record->total_cc_amount += $line->price_total;
        }

        if ($record->qty_to_invoice != 0) {
            $record->invoice_status = Enums\OrderInvoiceStatus::TO_INVOICED;
        }

        $record->save();
    }

    public static function collectLineTotals(OrderLine $line): OrderLine
    {
        $line->qty_received_manual = $line->qty_received;

        $line->qty_to_invoice = $line->qty_received - $line->qty_invoiced;

        $subTotal = $line->price_unit * $line->product_qty;

        $discountAmount = 0;

        if ($line->discount > 0) {
            $discountAmount = $subTotal * ($line->discount / 100);

            $subTotal = $subTotal - $discountAmount;
        }

        $taxIds = $line->taxes->pluck('id')->toArray();

        [$subTotal, $taxAmount] = app(MoveLineCalculationService::class)->collectionTaxes($taxIds, $subTotal, $line->product_qty);

        $line->price_subtotal = round($subTotal, 4);

        $line->price_tax = $taxAmount;

        $line->price_total = $subTotal + $taxAmount;

        $line->save();

        return $line;
    }
}
