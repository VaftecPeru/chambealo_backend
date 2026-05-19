<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTenantIdToAllTables extends Migration
{
    public function up()
    {
        // Agregar tenant_id a users
        if (!Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('user_id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a plans
        if (!Schema::hasColumn('plans', 'tenant_id')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('plan_id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a subscriptions
        if (!Schema::hasColumn('subscriptions', 'tenant_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('subscription_id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a payments
        if (!Schema::hasColumn('payments', 'tenant_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a brands
        if (!Schema::hasColumn('brands', 'tenant_id')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('brand_id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a products
        if (!Schema::hasColumn('products', 'tenant_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('product_id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a categories
        if (!Schema::hasColumn('categories', 'tenant_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('category_id');
                $table->index('tenant_id');
            });
        }
        
        // Agregar tenant_id a reviews
        if (!Schema::hasColumn('reviews', 'tenant_id')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('review_id');
                $table->index('tenant_id');
            });
        }
    }
    
    public function down()
    {
        // Eliminar columnas tenant_id
        $tables = ['users', 'plans', 'subscriptions', 'payments', 'brands', 'products', 'categories', 'reviews'];
        
        foreach ($tables as $tableName) {
            if (Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
}