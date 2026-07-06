<x-admin::layouts.anonymous>
    <div class="flex justify-center items-center min-h-screen bg-gray-100 dark:bg-cherry-900">
        <div class="w-full max-w-md p-8 bg-white dark:bg-cherry-800 rounded-lg shadow-lg">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
                    @lang('shopify::app.shopify.credential.install.title')
                </h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ session('shopify_install_shop') ?: request('shop') }}
                </p>
            </div>

            <x-admin::form
                :action="route('shopify.install.credentials')"
                method="POST"
            >
                @csrf

                <input type="hidden" name="shop" value="{{ session('shopify_install_shop') ?: request('shop') }}" />
                <input type="hidden" name="host" value="{{ request('host') }}" />
                <input type="hidden" name="hmac" value="{{ request('hmac') }}" />
                <input type="hidden" name="timestamp" value="{{ request('timestamp') }}" />

                <x-admin::form.control-group class="mb-4">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.credential.install.client_id')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="text"
                        id="clientId"
                        name="clientId"
                        :value="old('clientId')"
                        :label="trans('shopify::app.shopify.credential.install.client_id')"
                        :placeholder="trans('shopify::app.shopify.credential.install.client_id_placeholder')"
                        ::rules="{ required: true }"
                    />

                    <x-admin::form.control-group.error control-name="clientId" />
                </x-admin::form.control-group>

                <x-admin::form.control-group class="mb-6">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.credential.install.client_secret')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="password"
                        id="clientSecret"
                        name="clientSecret"
                        :value="old('clientSecret')"
                        :label="trans('shopify::app.shopify.credential.install.client_secret')"
                        :placeholder="trans('shopify::app.shopify.credential.install.client_secret_placeholder')"
                        ::rules="{ required: true }"
                    />

                    <x-admin::form.control-group.error control-name="clientSecret" />
                </x-admin::form.control-group>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="primary-button py-2 px-6"
                    >
                        @lang('shopify::app.shopify.credential.install.continue')
                    </button>
                </div>
            </x-admin::form>
        </div>
    </div>
</x-admin::layouts.anonymous>
