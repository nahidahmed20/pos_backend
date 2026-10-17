(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const permissions = new Set();
    const state = { user: null, branchId: null, branches: [], transferBranches: [], transferProducts: [], transfers: [], products: [], inventoryProducts: [], categories: [], suppliers: [], sales: [], purchases: [], expenses: [], cart: new Map(), customers: [], registers: [], session: null, catalogPage: 1, catalogLastPage: 1, salesPage: 1, salesLastPage: 1, searchTimer: null, dialogSubmit: null };
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const money = (amount) => `৳${Number(amount || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);

    async function api(path, options = {}) {
        const response = await fetch(`/api${path}`, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
        });
        const result = response.status === 204 ? {} : await response.json().catch(() => ({}));
        if (response.status === 401) {
            window.location.assign('/login');
            throw new Error('Your session has expired. Sign in again.');
        }
        if (!response.ok) {
            const validation = result.errors ? Object.values(result.errors).flat()[0] : null;
            throw new Error(validation || result.message || `Request failed (${response.status}).`);
        }
        return result;
    }

    function toast(message, isError = false) {
        const element = $('#toast');
        element.textContent = message;
        element.classList.toggle('error', isError);
        element.classList.add('show');
        window.clearTimeout(toast.timer);
        toast.timer = window.setTimeout(() => element.classList.remove('show'), 3200);
    }

    function setMessage(selector, message, success = false) {
        const element = $(selector);
        element.textContent = message;
        element.classList.toggle('success', success);
    }

    function showView(name) {
        $$('.page-view').forEach((view) => view.classList.toggle('active', view.id === `view-${name}`));
        $$('.nav-link').forEach((link) => link.classList.toggle('active', link.dataset.view === name));
        const link = $(`.nav-link[data-view="${name}"]`);
        $('#page-label').textContent = link?.textContent.trim() || 'Overview';
        if (name === 'pos') {
            loadCatalog();
            loadCustomers(true);
        } else if (name === 'cash') {
            loadCashData();
        } else if (name === 'products') {
            loadProducts();
        } else if (name === 'transfers') {
            loadTransfers();
        } else if (name === 'categories') {
            loadCategories();
        } else if (name === 'customers') {
            loadCustomers();
        } else if (name === 'purchases') {
            loadPurchases();
            loadPurchaseOptions();
        } else if (name === 'suppliers') {
            loadSuppliers();
        } else if (name === 'sales') {
            loadSales();
        } else if (name === 'expenses') {
            loadExpenses();
        } else if (name === 'reports') {
            loadReports();
        } else if (name === 'overview') {
            loadOverview();
        }
    }

    function applyPermissions() {
        $$('[data-permission]').forEach((element) => {
            element.classList.toggle('hidden', !permissions.has(element.dataset.permission));
        });
        const canAccessWorkspace = ['manage pos', 'manage products', 'manage purchases', 'manage expenses', 'manage branches', 'view reports']
            .some((permission) => permissions.has(permission));
        if (!canAccessWorkspace) {
            window.location.assign('/login');
        }
    }

    function setUser(user) {
        state.user = user;
        state.branchId = user.branch?.id ?? null;
        (user.permissions || []).forEach((permission) => permissions.add(permission));
        $('#user-name').textContent = user.name;
        $('#user-role').textContent = user.role || 'Team member';
        $('#user-avatar').textContent = user.name.trim().charAt(0).toUpperCase();
        $('#greeting-name').textContent = user.name.split(' ')[0];
        $('#branch-label').textContent = user.branch?.name || 'All branches';
        applyPermissions();
    }

    function setStats(container, stats) {
        const items = [
            ['Sales revenue', stats.sales_total ?? '—', `${stats.sales_count ?? 0} sales`, '↗'],
            ['Purchases', stats.purchase_total ?? '—', `${stats.purchase_count ?? 0} purchase orders`, '⇣'],
            ['Expenses', stats.expense_total ?? '—', 'Recorded expenses', '৳'],
        ];
        $(container).innerHTML = items.map(([title, amount, note, icon]) => `
            <article class="stat-card"><div class="stat-card-head"><span>${title}</span><span class="stat-icon">${icon}</span></div>
            <strong class="stat-value">${typeof amount === 'number' ? money(amount) : (String(amount).startsWith('৳') ? amount : money(amount))}</strong>
            <small class="stat-note">${note}</small></article>`).join('');
    }

    async function loadOverview() {
        const grid = $('#stats-grid');
        if (!permissions.has('view reports')) {
            grid.innerHTML = '<article class="stat-card"><div class="stat-card-head">Your point of sale</div><strong class="stat-value">Ready</strong><small class="stat-note">Start a sale or open a cash register.</small></article>';
            return;
        }
        try {
            const query = state.user.role === 'super-admin' && state.branchId ? `?branch_id=${state.branchId}` : '';
            const { data } = await api(`/reports/dashboard${query}`);
            setStats('#stats-grid', data);
        } catch (error) {
            grid.innerHTML = `<article class="panel muted">${escapeHtml(error.message)}</article>`;
        }
    }

    async function loadReports() {
        try {
            const query = state.user.role === 'super-admin' && state.branchId ? `?branch_id=${state.branchId}` : '';
            const { data } = await api(`/reports/dashboard${query}`);
            setStats('#report-stats', data);
        } catch (error) {
            $('#report-stats').innerHTML = `<article class="panel muted">${escapeHtml(error.message)}</article>`;
        }
    }

    function getCartTotals() {
        const subtotal = [...state.cart.values()].reduce((total, entry) => total + Number(entry.variant?.selling_price ?? entry.product.selling_price) * entry.quantity, 0);
        const discount = Math.max(0, Number($('#sale-discount').value || 0));
        const tax = Math.max(0, Number($('#sale-tax').value || 0));
        return { subtotal, total: Math.max(0, subtotal - discount + tax), discount, tax };
    }

    function renderCart() {
        const items = [...state.cart.values()];
        $('#cart-count').textContent = items.reduce((count, item) => count + item.quantity, 0);
        $('#cart-items').innerHTML = items.length ? items.map(({ product, variant, quantity, key }) => {
            const name = variant ? `${product.name} · ${variant.name}` : product.name;
            const price = Number(variant?.selling_price ?? product.selling_price);
            return `<div class="cart-row"><div><strong>${escapeHtml(name)}</strong><small>${money(price)} each</small>
            <div class="quantity-control"><button type="button" data-cart-action="minus" data-cart-key="${key}" aria-label="Decrease quantity">−</button><span>${quantity}</span><button type="button" data-cart-action="plus" data-cart-key="${key}" aria-label="Increase quantity">+</button></div></div>
            <div class="cart-row-side"><strong>${money(price * quantity)}</strong><button type="button" class="text-button" data-cart-action="remove" data-cart-key="${key}">Remove</button></div></div>`;
        }).join('') : '<div class="empty-state">Choose a product to get started.</div>';
        const { subtotal, total } = getCartTotals();
        $('#sale-subtotal').textContent = money(subtotal);
        $('#sale-total').textContent = money(total);
        const paid = $('#sale-paid');
        paid.max = total.toFixed(2);
        if (Number(paid.value) > total) paid.value = total.toFixed(2);
        $('#complete-sale').disabled = items.length === 0;
    }

    async function loadCatalog(page = 1) {
        state.catalogPage = page;
        $('#catalog-grid').innerHTML = '<div class="empty-state">Loading products…</div>';
        try {
            const search = $('#catalog-search').value.trim();
            const query = new URLSearchParams({ page: String(page) });
            if (search) query.set('search', search);
            if (state.user?.role === 'super-admin' && state.branchId) query.set('branch_id', String(state.branchId));
            const result = await api(`/pos/catalog?${query}`);
            const payload = result.data;
            state.products = payload.data || [];
            state.catalogPage = payload.current_page || 1;
            state.catalogLastPage = payload.last_page || 1;
            const catalogItems = state.products.flatMap((product) => {
                const variants = (product.variants || []).filter((variant) => variant.is_active);
                return variants.length
                    ? [...(product.stock ? [{ product, variant: null }] : []), ...variants.map((variant) => ({ product, variant }))]
                    : [{ product, variant: null }];
            });
            $('#catalog-grid').innerHTML = catalogItems.length ? catalogItems.map(({ product, variant }) => {
                const stock = Number((variant ? variant.sellable_quantity : product.sellable_quantity) ?? 0);
                const name = variant ? `${product.name} · ${variant.name}` : product.name;
                const sku = variant?.sku || product.sku;
                const price = variant?.selling_price ?? product.selling_price;
                return `<button class="product-card" type="button" data-add-product="${product.id}" data-variant-id="${variant?.id || ''}"><span class="product-stock">${product.track_stock ? `${stock} in stock` : 'Not tracked'}</span><strong>${escapeHtml(name)}</strong><small>${escapeHtml(sku)}${product.category?.name ? ` · ${escapeHtml(product.category.name)}` : ''}</small><span class="product-price">${money(price)}</span></button>`;
            }).join('') : '<div class="empty-state">No products match your search.</div>';
            $('#catalog-pagination').innerHTML = state.catalogLastPage > 1
                ? `<button type="button" data-catalog-page="${state.catalogPage - 1}" ${state.catalogPage <= 1 ? 'disabled' : ''}>← Previous</button><button type="button" data-catalog-page="${state.catalogPage + 1}" ${state.catalogPage >= state.catalogLastPage ? 'disabled' : ''}>Next →</button>`
                : '';
        } catch (error) {
            $('#catalog-grid').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    async function loadCustomers(forSale = false) {
        try {
            const { data } = await api('/customers');
            state.customers = (data.data || []).filter((customer) => !state.branchId || Number(customer.branch_id) === Number(state.branchId));
            if (forSale) {
                const select = $('#sale-customer');
                const selected = select.value;
                select.innerHTML = '<option value="">Walk-in customer</option>' + state.customers.map((customer) => `<option value="${customer.id}">${escapeHtml(customer.name)}${customer.phone ? ` · ${escapeHtml(customer.phone)}` : ''}</option>`).join('');
                select.value = selected;
            } else {
                $('#customers-table').innerHTML = renderTable(['Customer', 'Phone', 'Email', 'Credit limit', 'Opening balance', 'Actions'], state.customers.map((customer) => [
                    `<strong>${escapeHtml(customer.name)}</strong>${customer.contact_person ? `<small>${escapeHtml(customer.contact_person)}</small>` : ''}`,
                    escapeHtml(customer.phone),
                    escapeHtml(customer.email || '—'),
                    money(customer.credit_limit),
                    money(customer.opening_balance),
                    `<button class="text-button" data-edit-customer="${customer.id}">Edit</button> <button class="text-button danger-button" data-delete-customer="${customer.id}">Delete</button>`,
                ]), 'No customers added yet.');
            }
        } catch (error) {
            const target = forSale ? $('#sale-customer') : $('#customers-table');
            if (!forSale) target.innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
            else toast(error.message, true);
        }
    }

    function renderTable(headers, rows, emptyText) {
        if (!rows.length) return `<div class="empty-state">${emptyText}</div>`;
        return `<div class="table-wrap"><table><thead><tr>${headers.map((header) => `<th>${header}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${row.map((cell) => `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    }

    async function loadProducts() {
        try {
            const { data } = await api('/products');
            state.inventoryProducts = data.filter((product) => !state.branchId || Number(product.branch_id) === Number(state.branchId));
            const { data: categories } = await api('/categories');
            state.categories = categories.filter((category) => !state.branchId || Number(category.branch_id) === Number(state.branchId));
            fillSelect('#product-category', state.categories, 'Select category');
            const search = $('#product-search').value.trim().toLowerCase();
            const products = state.inventoryProducts.filter((product) => (!search || `${product.name} ${product.sku} ${product.barcode || ''}`.toLowerCase().includes(search)));
            $('#products-table').innerHTML = renderTable(['Product', 'SKU', 'Category', 'Selling price', 'Stock', 'Status', 'Actions'], products.map((product) => [
                `<strong>${escapeHtml(product.name)}</strong>`,
                escapeHtml(product.sku),
                escapeHtml(product.category?.name || '—'),
                money(product.selling_price),
                String(product.variants?.length
                    ? product.variants.reduce((quantity, variant) => quantity + Number(variant.stock?.quantity ?? 0), 0)
                    : product.stock?.quantity ?? 0),
                `<span class="pill ${product.is_active ? 'pill-open' : 'pill-closed'}">${product.is_active ? 'Active' : 'Inactive'}</span>`,
                `<button class="text-button" data-edit-product="${product.id}">Edit</button> <button class="text-button" data-add-variant="${product.id}">Variants</button> <button class="text-button" data-adjust-stock="${product.id}">Adjust stock</button> ${product.has_expiry ? `<button class="text-button" data-manage-batches="${product.id}">Batches</button>` : ''} <button class="text-button danger-button" data-delete-product="${product.id}">Delete</button>`,
            ]), 'No products available.');
        } catch (error) {
            $('#products-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    async function loadCategories() {
        try {
            const { data } = await api('/categories');
            state.categories = (data || []).filter((category) => !state.branchId || Number(category.branch_id) === Number(state.branchId));
            $('#categories-table').innerHTML = renderTable(['Category', 'Slug', 'Description', 'Actions'], state.categories.map((category) => [
                `<strong>${escapeHtml(category.name)}</strong>`,
                escapeHtml(category.slug || '—'),
                escapeHtml(category.description || '—'),
                `<button class="text-button" data-edit-category="${category.id}">Edit</button> <button class="text-button danger-button" data-delete-category="${category.id}">Delete</button>`,
            ]), 'No categories created yet.');
            fillSelect('#product-category', state.categories, 'Select category');
        } catch (error) {
            $('#categories-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    function fillSelect(selector, records, placeholder, labelKey = 'name') {
        const select = $(selector);
        if (!select) return;
        const current = select.value;
        select.innerHTML = `<option value="">${placeholder}</option>` + records.map((record) => `<option value="${record.id}">${escapeHtml(record[labelKey])}</option>`).join('');
        if ([...select.options].some((option) => option.value === current)) select.value = current;
    }

    function openDialog(title, fields, submitText, onSubmit) {
        $('#dialog-title').textContent = title;
        $('#dialog-fields').innerHTML = fields;
        $('#dialog-submit').textContent = submitText;
        $('#dialog-error').textContent = '';
        state.dialogSubmit = onSubmit;
        $('#action-dialog').showModal();
        $('#dialog-fields').querySelector('input, select, textarea')?.focus();
    }

    function closeDialog() {
        $('#action-dialog').close();
        state.dialogSubmit = null;
    }

    async function loadPurchases() {
        try {
            const { data } = await api('/purchases');
            const purchases = (data.data || []).filter((purchase) => !state.branchId || Number(purchase.branch_id) === Number(state.branchId));
            state.purchases = purchases;
            $('#purchases-table').innerHTML = renderTable(['Invoice', 'Supplier', 'Items / batch', 'Date', 'Total', 'Paid', 'Due', 'Status', 'Actions'], purchases.map((purchase) => [
                `<strong>${escapeHtml(purchase.invoice_number)}</strong>`,
                escapeHtml(purchase.supplier?.name || '—'),
                (purchase.items || []).map((item) => `<strong>${escapeHtml(item.product?.name || 'Product')}${item.variant?.name ? ` · ${escapeHtml(item.variant.name)}` : ''}</strong><small>Qty ${item.quantity}${item.batch_number ? ` · Lot ${escapeHtml(item.batch_number)}` : ''}${item.expiry_date ? ` · Expires ${escapeHtml(item.expiry_date)}` : ''}</small>`).join('<br>'),
                escapeHtml(purchase.purchased_at || '—'),
                money(purchase.total),
                money(purchase.paid_amount),
                money(purchase.due_amount),
                `<span class="pill ${purchase.payment_status === 'paid' ? 'pill-open' : 'pill-closed'}">${escapeHtml(purchase.payment_status)}</span>`,
                Number(purchase.due_amount) > 0 ? `<button class="text-button" data-pay-purchase="${purchase.id}">Record payment</button>` : '—',
            ]), 'No purchases found.');
        } catch (error) {
            $('#purchases-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    async function loadPurchaseOptions() {
        try {
            const [supplierResult, productResult] = await Promise.all([
                api('/suppliers'),
                api('/pos/catalog?purpose=purchase'),
            ]);
            state.suppliers = (supplierResult.data || []).filter((supplier) => supplier.is_active && (!state.branchId || Number(supplier.branch_id) === Number(state.branchId)));
            state.inventoryProducts = productResult.data.data || [];
            fillSelect('#purchase-supplier', state.suppliers, 'Select supplier');
            renderPurchaseLines();
        } catch (error) {
            toast(error.message, true);
        }
    }

    function renderPurchaseLines(extraLines = 0) {
        const lines = $$('#purchase-lines .purchase-line');
        const existing = lines.map((line) => ({
            item: line.querySelector('.purchase-line-item').value,
            quantity: line.querySelector('.purchase-line-quantity').value,
            unitCost: line.querySelector('.purchase-line-cost').value,
            batchNumber: line.querySelector('.purchase-line-batch').value,
            manufacturedDate: line.querySelector('.purchase-line-manufactured').value,
            expiryDate: line.querySelector('.purchase-line-expiry').value,
        }));
        const options = state.inventoryProducts.flatMap((product) => [
            `<option value="${product.id}:">${escapeHtml(product.name)} · ${escapeHtml(product.sku)}</option>`,
            ...(product.variants || []).filter((variant) => variant.is_active).map((variant) => `<option value="${product.id}:${variant.id}">${escapeHtml(product.name)} · ${escapeHtml(variant.name)} (${escapeHtml(variant.sku)})</option>`),
        ]).join('');
        const count = Math.max(1, existing.length + extraLines);
        $('#purchase-lines').innerHTML = Array.from({ length: count }, (_, index) => `
            <div class="purchase-line">
                <select class="purchase-line-item" aria-label="Purchase item ${index + 1}" required><option value="">Select product or variant</option>${options}</select>
                <input class="purchase-line-quantity" aria-label="Quantity ${index + 1}" type="number" min="1" step="1" value="${escapeHtml(existing[index]?.quantity || '1')}" required>
                <input class="purchase-line-cost" aria-label="Unit cost ${index + 1}" type="number" min="0" step="0.01" value="${escapeHtml(existing[index]?.unitCost || '')}" placeholder="Unit cost" required>
                <input class="purchase-line-batch" aria-label="Batch number ${index + 1}" maxlength="100" value="${escapeHtml(existing[index]?.batchNumber || '')}" placeholder="Batch number">
                <input class="purchase-line-manufactured" aria-label="Manufactured date ${index + 1}" type="date" value="${escapeHtml(existing[index]?.manufacturedDate || '')}">
                <input class="purchase-line-expiry" aria-label="Expiry date ${index + 1}" type="date" value="${escapeHtml(existing[index]?.expiryDate || '')}">
                ${index ? '<button type="button" class="text-button danger-button" data-remove-purchase-line aria-label="Remove item">Remove</button>' : '<span class="line-spacer"></span>'}
            </div>`).join('');
        $$('#purchase-lines .purchase-line').forEach((line, index) => {
            if (existing[index]?.item) line.querySelector('.purchase-line-item').value = existing[index].item;
        });
    }

    async function loadSuppliers() {
        try {
            const { data } = await api('/suppliers');
            state.suppliers = (data || []).filter((supplier) => !state.branchId || Number(supplier.branch_id) === Number(state.branchId));
            $('#suppliers-table').innerHTML = renderTable(['Supplier', 'Contact', 'Phone', 'Email', 'Credit limit', 'Opening balance', 'Actions'], state.suppliers.map((supplier) => [
                `<strong>${escapeHtml(supplier.name)}</strong>`,
                escapeHtml(supplier.contact_person || '—'),
                escapeHtml(supplier.phone || '—'),
                escapeHtml(supplier.email || '—'),
                money(supplier.credit_limit),
                money(supplier.opening_balance),
                `<button class="text-button" data-edit-supplier="${supplier.id}">Edit</button> <button class="text-button danger-button" data-delete-supplier="${supplier.id}">Delete</button>`,
            ]), 'No suppliers added yet.');
        } catch (error) {
            $('#suppliers-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    async function loadSales(page = 1) {
        try {
            const params = new URLSearchParams({ page: String(page), per_page: '25' });
            if (state.user.role === 'super-admin' && state.branchId) params.set('branch_id', String(state.branchId));
            const invoice = $('#sales-invoice-filter').value.trim();
            const status = $('#sales-status-filter').value;
            const fromDate = $('#sales-from-date').value;
            const toDate = $('#sales-to-date').value;
            if (invoice) params.set('invoice_number', invoice);
            if (status) params.set('payment_status', status);
            if (fromDate) params.set('from_date', fromDate);
            if (toDate) params.set('to_date', toDate);
            const { data } = await api(`/sales?${params}`);
            const sales = data.data || [];
            state.salesPage = data.current_page || 1;
            state.salesLastPage = data.last_page || 1;
            state.sales = sales;
            $('#sales-table').innerHTML = renderTable(['Invoice', 'Customer', 'Date', 'Total', 'Paid', 'Due', 'Status', 'Actions'], sales.map((sale) => [
                `<strong>${escapeHtml(sale.invoice_number)}</strong>`,
                escapeHtml(sale.customer?.name || 'Walk-in customer'),
                escapeHtml(sale.sold_at || '—'),
                money(sale.total),
                money(sale.paid_amount),
                money(sale.due_amount),
                `<span class="pill ${sale.payment_status === 'paid' ? 'pill-open' : 'pill-closed'}">${escapeHtml(sale.payment_status)}</span>`,
                `<button class="text-button" data-print-sale="${sale.id}">Receipt</button> ${Number(sale.due_amount) > 0 ? `<button class="text-button" data-pay-sale="${sale.id}">Payment</button>` : ''} <button class="text-button" data-return-sale="${sale.id}">Return</button>`,
            ]), 'No sales found.');
            $('#sales-pagination').innerHTML = state.salesLastPage > 1
                ? `<button type="button" data-sales-page="${state.salesPage - 1}" ${state.salesPage <= 1 ? 'disabled' : ''}>← Previous</button><span>Page ${state.salesPage} of ${state.salesLastPage}</span><button type="button" data-sales-page="${state.salesPage + 1}" ${state.salesPage >= state.salesLastPage ? 'disabled' : ''}>Next →</button>`
                : '';
        } catch (error) {
            $('#sales-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
            $('#sales-pagination').innerHTML = '';
        }
    }

    async function loadTransfers() {
        try {
            const [branchResult, productResult, transferResult] = await Promise.all([
                api('/branches'),
                api('/products'),
                api('/stock-transfers'),
            ]);
            const branches = branchResult.data || [];
            state.transferBranches = branches;
            state.transferProducts = (productResult.data || []).filter((product) => !state.branchId || Number(product.branch_id) === Number(state.branchId));
            const source = branches.find((branch) => Number(branch.id) === Number(state.branchId));
            $('#transfer-source').value = source?.name || 'Select a source branch in the top bar';
            fillSelect('#transfer-destination', branches.filter((branch) => Number(branch.id) !== Number(state.branchId)), 'Select destination branch');
            renderTransferLines();

            const transfers = transferResult.data?.data || [];
            state.transfers = transfers;
            $('#transfers-table').innerHTML = renderTable(['From', 'To', 'Items', 'Status', 'Date'], transfers.map((transfer) => [
                escapeHtml(transfer.from_branch?.name || '—'),
                escapeHtml(transfer.to_branch?.name || '—'),
                (transfer.items || []).map((item) => `${escapeHtml(item.product?.name || 'Product')}${item.variant?.name ? ` · ${escapeHtml(item.variant.name)}` : ''} × ${item.quantity}`).join('<br>'),
                `<span class="pill ${transfer.status === 'completed' ? 'pill-open' : 'pill-closed'}">${escapeHtml(transfer.status)}</span>`,
                escapeHtml(transfer.created_at || '—'),
            ]), 'No stock transfers yet.');
        } catch (error) {
            $('#transfers-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    function renderTransferLines(extraLines = 0) {
        const lines = $$('#transfer-lines .purchase-line');
        const existing = lines.map((line) => ({
            item: line.querySelector('.transfer-line-item').value,
            quantity: line.querySelector('.transfer-line-quantity').value,
        }));
        const options = state.transferProducts.flatMap((product) => [
            `<option value="${product.id}:">${escapeHtml(product.name)} · ${escapeHtml(product.sku)}</option>`,
            ...(product.variants || []).map((variant) => `<option value="${product.id}:${variant.id}">${escapeHtml(product.name)} · ${escapeHtml(variant.name)} (${escapeHtml(variant.sku)})</option>`),
        ]).join('');
        const count = Math.max(1, existing.length + extraLines);
        $('#transfer-lines').innerHTML = Array.from({ length: count }, (_, index) => `
            <div class="purchase-line transfer-line">
                <select class="transfer-line-item" aria-label="Transfer item ${index + 1}" required><option value="">Select product or variant</option>${options}</select>
                <input class="transfer-line-quantity" aria-label="Transfer quantity ${index + 1}" type="number" min="1" step="1" value="${escapeHtml(existing[index]?.quantity || '1')}" required>
                ${index ? '<button type="button" class="text-button danger-button" data-remove-transfer-line>Remove</button>' : ''}
            </div>`).join('');
        $$('#transfer-lines .purchase-line').forEach((line, index) => {
            if (existing[index]?.item) line.querySelector('.transfer-line-item').value = existing[index].item;
        });
    }

    async function printSale(sale) {
        const receipt = window.open('', '_blank', 'width=420,height=720');
        if (!receipt) {
            toast('Allow pop-ups to print a receipt.', true);
            return;
        }
        const rows = (sale.items || []).map((item) => `<tr><td>${escapeHtml(item.product?.name || 'Product')}${item.variant?.name ? ` · ${escapeHtml(item.variant.name)}` : ''} × ${item.quantity}</td><td>${money(item.total)}</td></tr>`).join('');
        receipt.document.write(`<!doctype html><html><head><title>${escapeHtml(sale.invoice_number)}</title><style>body{font:14px Arial,sans-serif;color:#222;margin:24px}h1{font-size:20px}p{color:#666}table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:9px 0;border-bottom:1px dashed #ddd}.right{text-align:right}.total{font-size:18px;font-weight:bold}@media print{button{display:none}}</style></head><body><h1>Aamar POS</h1><p>Invoice ${escapeHtml(sale.invoice_number)}<br>${escapeHtml(sale.sold_at || '')}<br>${escapeHtml(sale.customer?.name || 'Walk-in customer')}</p><table>${rows}</table><p>Subtotal: <span class="right">${money(sale.subtotal)}</span></p><p>Discount: <span class="right">${money(sale.discount)}</span></p><p class="total">Total: <span class="right">${money(sale.total)}</span></p><p>Paid: ${money(sale.paid_amount)} · Due: ${money(sale.due_amount)}</p><button onclick="window.print()">Print receipt</button></body></html>`);
        receipt.document.close();
        receipt.focus();
    }

    function openPaymentDialog(resource, record) {
        const isSale = resource === 'sale';
        const due = Number(record.due_amount);
        const methodOptions = isSale
            ? '<option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option>'
            : '<option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option>';
        openDialog(`Payment · ${record.invoice_number}`, `
            <p class="muted">Outstanding balance: <strong>${money(due)}</strong></p>
            <label>Amount<input name="amount" type="number" min="0.01" max="${due.toFixed(2)}" step="0.01" required></label>
            <label>Method<select name="method" required>${methodOptions}</select></label>
            <label>Reference<input name="reference" maxlength="255"></label>
            <p class="muted">${state.session ? `Cash payments will be recorded in ${escapeHtml(state.session.cash_register?.name || 'your open register')}.` : 'Open a register session before recording a cash payment.'}</p>`,
        'Record payment', async (values) => {
            const method = values.method;
            if (method === 'cash' && !state.session) throw new Error('Open a cash register session before recording a cash payment.');
            const payload = { amount: Number(values.amount), method, reference: values.reference || null };
            if (method === 'cash') payload.cash_register_session_id = state.session.id;
            await api(`/${isSale ? 'sales' : 'purchases'}/${record.id}/payments`, { method: 'POST', body: JSON.stringify(payload) });
            closeDialog();
            toast('Payment recorded.');
            if (isSale) await loadSales();
            else await loadPurchases();
            if (method === 'cash') await loadCashData();
        });
    }

    function openVariantCreator(product) {
        const variants = (product.variants || []).map((variant) => `
            <div class="variant-list-row">
                <div><strong>${escapeHtml(variant.name)}</strong><small>${escapeHtml(variant.sku)} · ${variant.is_active ? 'Active' : 'Inactive'} · ${Number(variant.stock?.quantity || 0)} in stock</small></div>
                <div><button type="button" class="text-button" data-edit-variant="${variant.id}" data-product-id="${product.id}">Edit</button>
                <button type="button" class="text-button ${variant.is_active ? 'danger-button' : ''}" data-toggle-variant="${variant.id}" data-product-id="${product.id}">${variant.is_active ? 'Deactivate' : 'Activate'}</button></div>
            </div>`).join('');
        openDialog(`Add variant · ${product.name}`, `
            ${variants ? `<div class="variant-list"><p class="eyebrow">EXISTING VARIANTS</p>${variants}</div>` : '<p class="muted">No variants created yet.</p>'}
            <label>Variant name<input name="name" placeholder="Red / Large" required></label>
            <label>SKU<input name="sku" required></label>
            <label>Barcode<input name="barcode"></label>
            <label>Attributes (JSON)<textarea name="attributes" rows="3" placeholder='{"color":"Red","size":"L"}'></textarea></label>
            <label>Purchase price<input name="purchase_price" type="number" min="0" step="0.01" value="${Number(product.purchase_price || 0)}"></label>
            <label>Selling price<input name="selling_price" type="number" min="0" step="0.01" required></label>
            <label>Minimum selling price<input name="min_selling_price" type="number" min="0" step="0.01" value="0"></label>`,
        'Create variant', async (values) => {
            const attributesText = values.attributes.trim();
            let attributes = null;
            if (attributesText) {
                try {
                    attributes = JSON.parse(attributesText);
                } catch {
                    throw new Error('Variant attributes must be valid JSON.');
                }
                if (Array.isArray(attributes) || attributes === null || typeof attributes !== 'object') {
                    throw new Error('Variant attributes must be a JSON object.');
                }
            }
            const payload = {
                name: values.name,
                sku: values.sku,
                selling_price: Number(values.selling_price),
                purchase_price: Number(values.purchase_price || 0),
                min_selling_price: Number(values.min_selling_price || 0),
                attributes,
            };
            if (values.barcode) payload.barcode = values.barcode;
            await api(`/products/${product.id}/variants`, { method: 'POST', body: JSON.stringify(payload) });
            closeDialog();
            toast('Product variant created. Add initial stock with a purchase or stock adjustment.');
            await loadProducts();
        });
    }

    function openVariantEditor(product, variant) {
        openDialog(`Edit variant · ${variant.name}`, `
            <label>Variant name<input name="name" value="${escapeHtml(variant.name)}" required></label>
            <label>SKU<input name="sku" value="${escapeHtml(variant.sku)}" required></label>
            <label>Barcode<input name="barcode" value="${escapeHtml(variant.barcode || '')}"></label>
            <label>Attributes (JSON)<textarea name="attributes" rows="3">${escapeHtml(JSON.stringify(variant.attributes || {}))}</textarea></label>
            <label>Purchase price<input name="purchase_price" type="number" min="0" step="0.01" value="${Number(variant.purchase_price || 0)}"></label>
            <label>Selling price<input name="selling_price" type="number" min="0" step="0.01" value="${Number(variant.selling_price)}" required></label>
            <label>Minimum selling price<input name="min_selling_price" type="number" min="0" step="0.01" value="${Number(variant.min_selling_price || 0)}"></label>
            <label>Status<select name="is_active"><option value="true" ${variant.is_active ? 'selected' : ''}>Active</option><option value="false" ${!variant.is_active ? 'selected' : ''}>Inactive</option></select></label>`,
        'Save changes', async (values) => {
            const attributesText = values.attributes.trim();
            let attributes = null;
            if (attributesText) {
                try {
                    attributes = JSON.parse(attributesText);
                } catch {
                    throw new Error('Variant attributes must be valid JSON.');
                }
                if (Array.isArray(attributes) || attributes === null || typeof attributes !== 'object') {
                    throw new Error('Variant attributes must be a JSON object.');
                }
            }
            const payload = {
                name: values.name,
                sku: values.sku,
                barcode: values.barcode || null,
                attributes,
                purchase_price: Number(values.purchase_price || 0),
                selling_price: Number(values.selling_price),
                min_selling_price: Number(values.min_selling_price || 0),
                is_active: values.is_active === 'true',
            };
            await api(`/products/${product.id}/variants/${variant.id}`, { method: 'PATCH', body: JSON.stringify(payload) });
            closeDialog();
            toast('Product variant updated.');
            await loadProducts();
        });
    }

    function openReturnDialog(sale) {
        const returned = new Map();
        (sale.returns || []).flatMap((saleReturn) => saleReturn.items || []).forEach((item) => {
            returned.set(item.sale_item_id, (returned.get(item.sale_item_id) || 0) + Number(item.quantity));
        });
        const items = (sale.items || []).map((item) => ({
            ...item,
            remainingQuantity: Number(item.quantity) - (returned.get(item.id) || 0),
        })).filter((item) => item.remainingQuantity > 0);
        if (!items.length) {
            toast('This sale has no item details available to return.', true);
            return;
        }
        const itemOptions = items.map((item) => `<option value="${item.id}" data-max-quantity="${item.remainingQuantity}">${escapeHtml(item.product?.name || 'Product')}${item.variant?.name ? ` · ${escapeHtml(item.variant.name)}` : ''} · ${item.remainingQuantity} returnable · ${money(item.unit_price)}</option>`).join('');
        openDialog(`Return · ${sale.invoice_number}`, `
            <label>Item to return<select name="sale_item_id" required>${itemOptions}</select></label>
            <label>Quantity<input name="quantity" type="number" min="1" max="${items[0].remainingQuantity}" step="1" value="1" required></label>
            <label>Refund amount<input name="refund_amount" type="number" min="0" max="${Number(sale.paid_amount).toFixed(2)}" step="0.01" value="0" required></label>
            <label>Refund method<select name="refund_method"><option value="">No refund</option><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option></select></label>
            <label>Note<input name="note" maxlength="255"></label>`,
        'Process return', async (values) => {
            const refundAmount = Number(values.refund_amount);
            const refundMethod = values.refund_method || null;
            if (refundAmount > 0 && !refundMethod) throw new Error('Choose a refund method for a refund.');
            if (refundMethod === 'cash' && !state.session) throw new Error('Open a cash register session before recording a cash refund.');
            const now = new Date();
            const payload = {
                return_number: `RET-${now.toISOString().replace(/[-:TZ.]/g, '').slice(0, 14)}-${Math.floor(Math.random() * 900 + 100)}`,
                refund_amount: refundAmount,
                refund_method: refundMethod,
                note: values.note || null,
                items: [{ sale_item_id: Number(values.sale_item_id), quantity: Number(values.quantity) }],
            };
            if (refundMethod === 'cash') payload.cash_register_session_id = state.session.id;
            await api(`/sales/${sale.id}/returns`, { method: 'POST', body: JSON.stringify(payload) });
            closeDialog();
            toast('Sale return recorded.');
            await loadSales();
            if (refundMethod === 'cash') await loadCashData();
        });
    }

    function openProductEditor(product) {
        const options = state.categories.map((category) => `<option value="${category.id}" ${Number(category.id) === Number(product.category_id) ? 'selected' : ''}>${escapeHtml(category.name)}</option>`).join('');
        openDialog(`Edit · ${product.name}`, `
            <label>Name<input name="name" value="${escapeHtml(product.name)}" required></label>
            <label>SKU<input name="sku" value="${escapeHtml(product.sku)}" required></label>
            <label>Category<select name="category_id" required>${options}</select></label>
            <label>Barcode<input name="barcode" value="${escapeHtml(product.barcode || '')}"></label>
            <label>Purchase price<input name="purchase_price" type="number" min="0" step="0.01" value="${Number(product.purchase_price || 0)}"></label>
            <label>Selling price<input name="selling_price" type="number" min="0" step="0.01" value="${Number(product.selling_price)}" required></label>
            <label>Expiry tracking<select name="has_expiry"><option value="false" ${!product.has_expiry ? 'selected' : ''}>Not tracked</option><option value="true" ${product.has_expiry ? 'selected' : ''}>Track batches and expiry</option></select></label>
            <label>Product status<select name="is_active"><option value="true" ${product.is_active ? 'selected' : ''}>Active</option><option value="false" ${!product.is_active ? 'selected' : ''}>Inactive</option></select></label>`,
        'Save changes', async (values) => {
            values.category_id = Number(values.category_id);
            values.purchase_price = Number(values.purchase_price);
            values.selling_price = Number(values.selling_price);
            values.is_active = values.is_active === 'true';
            values.has_expiry = values.has_expiry === 'true';
            await api(`/products/${product.id}`, { method: 'PATCH', body: JSON.stringify(values) });
            closeDialog();
            toast('Product updated.');
            await loadProducts();
        });
    }

    function openStockAdjustment(product) {
        const variants = product.variants || [];
        const variantField = variants.length
            ? `<label>Product variant<select name="variant_id"><option value="">Base product stock</option>${variants.map((variant) => `<option value="${variant.id}">${escapeHtml(variant.name)} · ${Number(variant.stock?.quantity ?? 0)} available</option>`).join('')}</select></label>`
            : '';
        const batchFields = product.has_expiry
            ? `<label data-adjustment-batch-field>Batch number<input name="batch_number" maxlength="100"></label>
                <label data-adjustment-batch-field>Manufactured date<input name="manufactured_date" type="date"></label>
                <label data-adjustment-batch-field>Expiry date<input name="expiry_date" type="date"></label>`
            : '';
        openDialog(`Adjust stock · ${product.name}`, `
            <p class="muted">Current quantity: <strong class="stock-current">${Number(product.stock?.quantity ?? 0)}</strong></p>
            ${variantField}
            <label>Adjustment<select name="direction"><option value="add">Add stock</option><option value="remove">Remove stock</option></select></label>
            <label>Quantity<input name="quantity" type="number" min="1" step="1" required></label>
            ${batchFields}
            <label>Reason<input name="note" maxlength="255" required></label>`,
        'Save adjustment', async (values) => {
            const quantity = Number(values.quantity) * (values.direction === 'remove' ? -1 : 1);
            const payload = { quantity, note: values.note };
            if (values.variant_id) payload.variant_id = Number(values.variant_id);
            for (const field of ['batch_number', 'manufactured_date', 'expiry_date']) {
                if (values[field]) payload[field] = values[field];
            }
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            await api('/stock-movements', { method: 'POST', body: JSON.stringify({ product_id: product.id, ...payload }) });
            closeDialog();
            toast('Stock adjustment recorded.');
            await loadProducts();
        });
    }

    async function openBatchManager(product) {
        try {
            const { data } = await api(`/stock-batches?product_id=${product.id}`);
            const batches = data.data || [];
            const variants = product.variants || [];
            const variantOptions = variants.length
                ? `<label>Variant<select name="variant_id"><option value="">Base product stock</option>${variants.map((variant) => `<option value="${variant.id}">${escapeHtml(variant.name)}</option>`).join('')}</select></label>`
                : '';
            const rows = batches.map((batch) => [
                escapeHtml(batch.batch_number || '—'),
                escapeHtml(batch.variant?.name || 'Base product'),
                String(batch.quantity),
                escapeHtml(batch.manufactured_date || '—'),
                escapeHtml(batch.expiry_date || 'No expiry'),
            ]);
            openDialog(`Batches · ${product.name}`, `
                <p class="muted">Register existing stock here. Legacy stock stays unavailable for sale until its lots are registered.</p>
                ${renderTable(['Batch', 'Variant', 'Quantity', 'Manufactured', 'Expires'], rows, 'No batches registered yet.')}
                ${variantOptions}
                <label>Quantity<input name="quantity" type="number" min="1" step="1" required></label>
                <label>Batch number<input name="batch_number" maxlength="100"></label>
                <label>Manufactured date<input name="manufactured_date" type="date"></label>
                <label>Expiry date<input name="expiry_date" type="date" required></label>
                <label>Note<input name="note" maxlength="255"></label>`,
            'Register opening batch', async (values) => {
                const payload = {
                    product_id: product.id,
                    quantity: Number(values.quantity),
                    expiry_date: values.expiry_date,
                };
                if (values.variant_id) payload.variant_id = Number(values.variant_id);
                for (const field of ['batch_number', 'manufactured_date', 'note']) {
                    if (values[field]) payload[field] = values[field];
                }
                if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
                await api('/stock-batches/opening', { method: 'POST', body: JSON.stringify(payload) });
                closeDialog();
                toast('Opening batch registered.');
                await loadProducts();
            });
        } catch (error) {
            toast(error.message, true);
        }
    }

    async function deleteResource(path, label, refresh) {
        if (!window.confirm(`Delete this ${label}? This cannot be undone.`)) return;
        try {
            await api(path, { method: 'DELETE' });
            toast(`${label.charAt(0).toUpperCase()}${label.slice(1)} deleted.`);
            await refresh();
        } catch (error) {
            toast(error.message, true);
        }
    }

    function openSimpleEditor(title, fields, submitText, onSubmit) {
        openDialog(title, fields, submitText, onSubmit);
    }

    async function loadExpenses() {
        try {
            const { data } = await api('/expenses');
            const expenses = (data.data || []).filter((expense) => !state.branchId || Number(expense.branch_id) === Number(state.branchId));
            state.expenses = expenses;
            $('#expenses-table').innerHTML = renderTable(['Category', 'Date', 'Amount', 'Method', 'Reference', 'Note', 'Actions'], expenses.map((expense) => [
                `<strong>${escapeHtml(expense.category)}</strong>`,
                escapeHtml(expense.spent_at || '—'),
                money(expense.amount),
                escapeHtml(expense.payment_method || 'cash'),
                escapeHtml(expense.reference || '—'),
                escapeHtml(expense.note || '—'),
                `<button class="text-button" data-edit-expense="${expense.id}">Edit</button> <button class="text-button danger-button" data-delete-expense="${expense.id}">Delete</button>`,
            ]), 'No expenses found.');
        } catch (error) {
            $('#expenses-table').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    async function loadCashData() {
        try {
            const [registerResult, sessionResult] = await Promise.all([
                api('/cash-registers'),
                api('/cash-register-sessions'),
            ]);
            state.registers = (registerResult.data || []).filter((register) => !state.branchId || Number(register.branch_id) === Number(state.branchId));
            const sessions = (sessionResult.data.data || []).filter((session) => !state.branchId || Number(session.cash_register?.branch_id) === Number(state.branchId));
            state.session = sessions.find((session) => session.status === 'open') || null;
            renderRegisters();
            renderSessions(sessions);
            renderSessionState();
        } catch (error) {
            $('#register-list').innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
            setMessage('#cash-message', error.message);
        }
    }

    function renderRegisters() {
        $('#register-select').innerHTML = state.registers.length
            ? state.registers.filter((register) => register.is_active).map((register) => `<option value="${register.id}">${escapeHtml(register.name)} · ${escapeHtml(register.code)}</option>`).join('')
            : '<option value="">No active registers</option>';
        $('#register-list').innerHTML = state.registers.length ? state.registers.map((register) => `
            <div class="data-item"><div><strong>${escapeHtml(register.name)}</strong><small>${escapeHtml(register.code)}${register.location ? ` · ${escapeHtml(register.location)}` : ''}</small></div><span class="pill ${register.is_active ? 'pill-open' : 'pill-closed'}">${register.is_active ? 'Active' : 'Inactive'}</span></div>`).join('') : '<div class="empty-state">No registers set up for this branch.</div>';
    }

    function renderSessions(sessions) {
        $('#session-history').innerHTML = sessions.length ? sessions.slice(0, 10).map((session) => `
            <div class="data-item"><div><strong>${escapeHtml(session.cash_register?.name || 'Cash register')}</strong><small>${escapeHtml(session.user?.name || '')} · ${new Date(session.opened_at).toLocaleString()}</small></div><div class="data-item-right"><span class="pill ${session.status === 'open' ? 'pill-open' : 'pill-closed'}">${escapeHtml(session.status)}</span><small>${session.status === 'open' ? `Opening ${money(session.opening_balance)}` : `Difference ${money(session.difference)}`}</small></div></div>`).join('') : '<div class="empty-state">No register sessions found.</div>';
    }

    function renderSessionState() {
        const isOpen = Boolean(state.session);
        $('#open-session-form').classList.toggle('hidden', isOpen);
        $('#active-session-panel').classList.toggle('hidden', !isOpen);
        $('#session-heading').textContent = isOpen ? state.session.cash_register?.name || 'Active session' : 'Open a register';
        $('#cash-status').textContent = isOpen ? 'Open' : 'Closed';
        $('#cash-status').className = `pill ${isOpen ? 'pill-open' : 'pill-muted'}`;
        $('#session-opening').textContent = isOpen ? money(state.session.opening_balance) : money(0);
        $('#pos-session-status').textContent = isOpen ? `Cash session open · ${state.session.cash_register?.name || ''}` : 'No open register session';
        $('#pos-session-status').className = `pill ${isOpen ? 'pill-open' : 'pill-muted'}`;
    }

    async function createSale() {
        const button = $('#complete-sale');
        const { total, discount, tax } = getCartTotals();
        const paid = Number($('#sale-paid').value || 0);
        const method = $('#sale-method').value;
        if (paid > total) {
            setMessage('#sale-message', 'Paid amount cannot exceed the total.');
            return;
        }
        if (method === 'cash' && paid > 0 && !state.session) {
            setMessage('#sale-message', 'Open a cash register session before recording cash payment.');
            return;
        }
        button.disabled = true;
        setMessage('#sale-message', 'Completing sale…');
        try {
            const now = new Date();
            const invoice = `INV-${now.toISOString().replace(/[-:TZ.]/g, '').slice(0, 14)}-${Math.floor(Math.random() * 900 + 100)}`;
            const payload = {
                invoice_number: invoice,
                items: [...state.cart.values()].map(({ product, variant, quantity }) => ({
                    product_id: product.id,
                    ...(variant ? { variant_id: variant.id } : {}),
                    quantity,
                })),
                discount,
                tax,
                paid_amount: paid,
                payment_method: method === 'due' && paid === 0 ? 'due' : method,
                customer_id: $('#sale-customer').value || null,
            };
            if (method === 'cash' && paid > 0) payload.cash_register_session_id = state.session.id;
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            const result = await api('/sales', { method: 'POST', body: JSON.stringify(payload) });
            state.cart.clear();
            $('#sale-discount').value = '0';
            $('#sale-tax').value = '0';
            $('#sale-paid').value = '0';
            renderCart();
            setMessage('#sale-message', `Sale ${result.data.invoice_number} completed · ${money(result.data.total)}.`, true);
            toast('Sale completed successfully.');
            if (state.session) await loadCashData();
            await loadCatalog();
        } catch (error) {
            setMessage('#sale-message', error.message);
            toast(error.message, true);
        } finally {
            button.disabled = state.cart.size === 0;
        }
    }

    function formObject(form) {
        return Object.fromEntries(new FormData(form).entries());
    }

    function setUpEvents() {
        $('#workspace-nav').addEventListener('click', (event) => {
            const link = event.target.closest('[data-view]');
            if (link) showView(link.dataset.view);
        });
        $('#dialog-fields').addEventListener('click', async (event) => {
            const editButton = event.target.closest('[data-edit-variant]');
            const toggleButton = event.target.closest('[data-toggle-variant]');
            const button = editButton || toggleButton;
            if (!button) return;
            const product = state.inventoryProducts.find((item) => Number(item.id) === Number(button.dataset.productId));
            const variant = product?.variants?.find((item) => Number(item.id) === Number(button.dataset.editVariant || button.dataset.toggleVariant));
            if (!product || !variant) return;
            if (editButton) {
                openVariantEditor(product, variant);
                return;
            }
            const isActive = !variant.is_active;
            if (!isActive && !window.confirm(`Deactivate ${variant.name}? It will no longer be available for sale.`)) return;
            try {
                await api(`/products/${product.id}/variants/${variant.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ is_active: isActive }),
                });
                closeDialog();
                toast(`Variant ${isActive ? 'activated' : 'deactivated'}.`);
                await loadProducts();
            } catch (error) {
                toast(error.message, true);
            }
        });
        $$('[data-open-view]').forEach((button) => button.addEventListener('click', () => showView(button.dataset.openView)));
        $('#logout-button').addEventListener('click', async () => {
            try {
                const response = await fetch('/logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error('Unable to sign out. Please try again.');
                window.location.assign('/login');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#catalog-search').addEventListener('input', () => {
            window.clearTimeout(state.searchTimer);
            state.searchTimer = window.setTimeout(() => loadCatalog(1), 250);
        });
        $('#catalog-grid').addEventListener('click', (event) => {
            const button = event.target.closest('[data-add-product]');
            if (!button) return;
            const product = state.products.find((item) => item.id === Number(button.dataset.addProduct));
            if (!product) return;
            const variant = (product.variants || []).find((item) => Number(item.id) === Number(button.dataset.variantId)) || null;
            const key = variant ? `v-${variant.id}` : `p-${product.id}`;
            const existing = state.cart.get(key);
            state.cart.set(key, { key, product, variant, quantity: (existing?.quantity || 0) + 1 });
            renderCart();
        });
        $('#catalog-pagination').addEventListener('click', (event) => {
            const button = event.target.closest('[data-catalog-page]');
            if (button && !button.disabled) loadCatalog(Number(button.dataset.catalogPage));
        });
        $('#cart-items').addEventListener('click', (event) => {
            const button = event.target.closest('[data-cart-action]');
            if (!button) return;
            const key = button.dataset.cartKey;
            const item = state.cart.get(key);
            if (button.dataset.cartAction === 'remove' || (button.dataset.cartAction === 'minus' && item.quantity <= 1)) state.cart.delete(key);
            else state.cart.set(key, { ...item, quantity: item.quantity + (button.dataset.cartAction === 'plus' ? 1 : -1) });
            renderCart();
        });
        $('#clear-cart').addEventListener('click', () => {
            state.cart.clear();
            renderCart();
            setMessage('#sale-message', '');
        });
        $('#sale-discount').addEventListener('input', renderCart);
        $('#sale-tax').addEventListener('input', renderCart);
        $('#complete-sale').addEventListener('click', createSale);
        $('#open-session').addEventListener('click', async () => {
            if (!$('#register-select').value) {
                setMessage('#cash-message', 'Add or select an active cash register first.');
                return;
            }
            try {
                const result = await api('/cash-register-sessions', { method: 'POST', body: JSON.stringify({
                    cash_register_id: Number($('#register-select').value),
                    opening_balance: Number($('#opening-balance').value || 0),
                    opening_note: $('#opening-note').value || null,
                }) });
                state.session = result.data;
                setMessage('#cash-message', 'Register session opened.', true);
                await loadCashData();
                toast('Cash register session opened.');
            } catch (error) {
                setMessage('#cash-message', error.message);
            }
        });
        $('#record-movement').addEventListener('click', async () => {
            if (!state.session) return;
            try {
                await api(`/cash-register-sessions/${state.session.id}/movements`, { method: 'POST', body: JSON.stringify({
                    direction: $('#movement-direction').value,
                    amount: Number($('#movement-amount').value),
                    description: $('#movement-description').value || null,
                }) });
                $('#movement-amount').value = '';
                $('#movement-description').value = '';
                setMessage('#cash-message', 'Cash movement recorded.', true);
                await loadCashData();
            } catch (error) {
                setMessage('#cash-message', error.message);
            }
        });
        $('#close-session').addEventListener('click', async () => {
            if (!state.session || $('#closing-balance').value === '') {
                setMessage('#cash-message', 'Enter the counted closing cash amount.');
                return;
            }
            try {
                const result = await api(`/cash-register-sessions/${state.session.id}/close`, { method: 'POST', body: JSON.stringify({
                    closing_balance: Number($('#closing-balance').value),
                    closing_note: $('#closing-note').value || null,
                }) });
                setMessage('#cash-message', `Session closed. Expected ${money(result.data.expected_balance)}, difference ${money(result.data.difference)}.`, true);
                state.session = null;
                $('#closing-balance').value = '';
                await loadCashData();
            } catch (error) {
                setMessage('#cash-message', error.message);
            }
        });
        $('#refresh-sessions').addEventListener('click', loadCashData);
        $('#register-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                const payload = formObject(event.currentTarget);
                if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
                await api('/cash-registers', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                await loadCashData();
                toast('Cash register added.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#toggle-customer-form').addEventListener('click', () => $('#customer-form-panel').classList.toggle('hidden'));
        $('#customer-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = formObject(event.currentTarget);
            payload.credit_limit = Number(payload.credit_limit || 0);
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            if (!payload.email) delete payload.email;
            try {
                await api('/customers', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                $('#customer-form-panel').classList.add('hidden');
                await loadCustomers();
                toast('Customer added.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#toggle-expense-form').addEventListener('click', () => $('#expense-form-panel').classList.toggle('hidden'));
        $('#expense-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = formObject(event.currentTarget);
            payload.amount = Number(payload.amount);
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            for (const key of ['reference', 'receipt_path', 'note']) if (!payload[key]) delete payload[key];
            try {
                await api('/expenses', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                $('#expense-form-panel').classList.add('hidden');
                await loadExpenses();
                toast('Expense saved.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#toggle-product-form').addEventListener('click', async () => {
            await loadCategories();
            $('#product-form-panel').classList.toggle('hidden');
        });
        $('#product-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = formObject(event.currentTarget);
            for (const key of ['category_id', 'purchase_price', 'selling_price', 'tax_rate']) payload[key] = Number(payload[key]);
            payload.has_expiry = payload.has_expiry === 'true';
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            try {
                await api('/products', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                $('#product-form-panel').classList.add('hidden');
                await loadProducts();
                toast('Product added. Receive stock through a purchase or stock adjustment.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#products-table').addEventListener('click', async (event) => {
            const editButton = event.target.closest('[data-edit-product]');
            const stockButton = event.target.closest('[data-adjust-stock]');
            const variantButton = event.target.closest('[data-add-variant]');
            const batchButton = event.target.closest('[data-manage-batches]');
            const deleteButton = event.target.closest('[data-delete-product]');
            const id = Number(editButton?.dataset.editProduct || stockButton?.dataset.adjustStock || variantButton?.dataset.addVariant || batchButton?.dataset.manageBatches || deleteButton?.dataset.deleteProduct);
            const product = state.inventoryProducts.find((item) => Number(item.id) === id);
            if (!product) return;
            if (editButton) {
                await loadCategories();
                openProductEditor(product);
            } else if (variantButton) {
                openVariantCreator(product);
            } else if (stockButton) {
                openStockAdjustment(product);
            } else if (batchButton) {
                await openBatchManager(product);
            } else if (deleteButton) {
                await deleteResource(`/products/${id}`, 'product', loadProducts);
            }
        });
        $('#toggle-category-form').addEventListener('click', () => $('#category-form-panel').classList.toggle('hidden'));
        $('#category-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = formObject(event.currentTarget);
            if (!payload.slug) delete payload.slug;
            if (!payload.description) delete payload.description;
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            try {
                await api('/categories', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                $('#category-form-panel').classList.add('hidden');
                await loadCategories();
                toast('Category added.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#categories-table').addEventListener('click', async (event) => {
            const editButton = event.target.closest('[data-edit-category]');
            const deleteButton = event.target.closest('[data-delete-category]');
            const id = Number(editButton?.dataset.editCategory || deleteButton?.dataset.deleteCategory);
            const category = state.categories.find((item) => Number(item.id) === id);
            if (!category) return;
            if (editButton) {
                openSimpleEditor(`Edit · ${category.name}`, `
                    <label>Name<input name="name" value="${escapeHtml(category.name)}" required></label>
                    <label>Slug<input name="slug" value="${escapeHtml(category.slug || '')}"></label>
                    <label>Description<textarea name="description" rows="3">${escapeHtml(category.description || '')}</textarea></label>`,
                'Save changes', async (values) => {
                    for (const key of ['slug', 'description']) if (!values[key]) values[key] = null;
                    await api(`/categories/${id}`, { method: 'PATCH', body: JSON.stringify(values) });
                    closeDialog();
                    toast('Category updated.');
                    await loadCategories();
                });
            } else if (deleteButton) {
                await deleteResource(`/categories/${id}`, 'category', loadCategories);
            }
        });
        $('#toggle-supplier-form').addEventListener('click', () => $('#supplier-form-panel').classList.toggle('hidden'));
        $('#supplier-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = formObject(event.currentTarget);
            payload.credit_limit = Number(payload.credit_limit || 0);
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            for (const key of ['email', 'phone', 'contact_person', 'tax_number', 'address']) if (!payload[key]) delete payload[key];
            try {
                await api('/suppliers', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                $('#supplier-form-panel').classList.add('hidden');
                await loadSuppliers();
                toast('Supplier added.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#suppliers-table').addEventListener('click', async (event) => {
            const editButton = event.target.closest('[data-edit-supplier]');
            const deleteButton = event.target.closest('[data-delete-supplier]');
            const id = Number(editButton?.dataset.editSupplier || deleteButton?.dataset.deleteSupplier);
            const supplier = state.suppliers.find((item) => Number(item.id) === id);
            if (!supplier) return;
            if (editButton) {
                openSimpleEditor(`Edit · ${supplier.name}`, `
                    <label>Name<input name="name" value="${escapeHtml(supplier.name)}" required></label>
                    <label>Phone<input name="phone" value="${escapeHtml(supplier.phone || '')}"></label>
                    <label>Email<input name="email" type="email" value="${escapeHtml(supplier.email || '')}"></label>
                    <label>Contact person<input name="contact_person" value="${escapeHtml(supplier.contact_person || '')}"></label>
                    <label>Tax number<input name="tax_number" value="${escapeHtml(supplier.tax_number || '')}"></label>
                    <label>Credit limit<input name="credit_limit" type="number" min="0" step="0.01" value="${Number(supplier.credit_limit || 0)}"></label>`,
                'Save changes', async (values) => {
                    values.credit_limit = Number(values.credit_limit || 0);
                    for (const key of ['phone', 'email', 'contact_person', 'tax_number']) if (!values[key]) values[key] = null;
                    await api(`/suppliers/${id}`, { method: 'PATCH', body: JSON.stringify(values) });
                    closeDialog();
                    toast('Supplier updated.');
                    await loadSuppliers();
                });
            } else if (deleteButton) {
                await deleteResource(`/suppliers/${id}`, 'supplier', loadSuppliers);
            }
        });
        $('#toggle-purchase-form').addEventListener('click', async () => {
            $('#purchase-form').elements.purchased_at.value ||= new Date().toISOString().slice(0, 10);
            await loadPurchaseOptions();
            $('#purchase-form-panel').classList.toggle('hidden');
        });
        $('#add-purchase-line').addEventListener('click', () => renderPurchaseLines(1));
        $('#purchase-lines').addEventListener('click', (event) => {
            if (!event.target.closest('[data-remove-purchase-line]')) return;
            event.target.closest('.purchase-line').remove();
            renderPurchaseLines();
        });
        $('#purchase-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const values = formObject(event.currentTarget);
            const paid = Number(values.paid_amount || 0);
            const paymentMethod = values.payment_method || 'cash';
            const items = $$('#purchase-lines .purchase-line').map((line) => {
                const [productId, variantId] = line.querySelector('.purchase-line-item').value.split(':');
                return {
                    product_id: Number(productId),
                    ...(variantId ? { variant_id: Number(variantId) } : {}),
                    quantity: Number(line.querySelector('.purchase-line-quantity').value),
                    unit_cost: Number(line.querySelector('.purchase-line-cost').value),
                    ...(line.querySelector('.purchase-line-batch').value ? { batch_number: line.querySelector('.purchase-line-batch').value } : {}),
                    ...(line.querySelector('.purchase-line-manufactured').value ? { manufactured_date: line.querySelector('.purchase-line-manufactured').value } : {}),
                    ...(line.querySelector('.purchase-line-expiry').value ? { expiry_date: line.querySelector('.purchase-line-expiry').value } : {}),
                };
            });
            if (paid > 0 && paymentMethod === 'cash' && !state.session) {
                toast('Open a cash register session before recording a cash purchase payment.', true);
                return;
            }
            const payload = {
                supplier_id: Number(values.supplier_id),
                invoice_number: values.invoice_number,
                purchased_at: values.purchased_at,
                paid_amount: paid,
                payment_method: paymentMethod,
                discount: Number(values.discount || 0),
                tax: Number(values.tax || 0),
                items,
            };
            if (values.note) payload.note = values.note;
            if (paid > 0 && paymentMethod === 'cash') payload.cash_register_session_id = state.session.id;
            if (state.user.role === 'super-admin' && state.branchId) payload.branch_id = state.branchId;
            try {
                await api('/purchases', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                $('#purchase-form-panel').classList.add('hidden');
                await Promise.all([loadPurchases(), loadProducts()]);
                if (paid > 0 && paymentMethod === 'cash') await loadCashData();
                toast('Purchase received and stock updated.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#purchases-table').addEventListener('click', (event) => {
            const button = event.target.closest('[data-pay-purchase]');
            const purchase = state.purchases.find((item) => Number(item.id) === Number(button?.dataset.payPurchase));
            if (purchase) openPaymentDialog('purchase', purchase);
        });
        $('#add-transfer-line').addEventListener('click', () => renderTransferLines(1));
        $('#transfer-lines').addEventListener('click', (event) => {
            if (!event.target.closest('[data-remove-transfer-line]')) return;
            event.target.closest('.purchase-line').remove();
            renderTransferLines();
        });
        $('#transfer-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!state.branchId) {
                toast('Select a source branch before transferring stock.', true);
                return;
            }
            const values = formObject(event.currentTarget);
            const items = $$('#transfer-lines .purchase-line').map((line) => {
                const [productId, variantId] = line.querySelector('.transfer-line-item').value.split(':');
                return {
                    product_id: Number(productId),
                    ...(variantId ? { variant_id: Number(variantId) } : {}),
                    quantity: Number(line.querySelector('.transfer-line-quantity').value),
                };
            });
            const payload = {
                from_branch_id: state.branchId,
                to_branch_id: Number(values.to_branch_id),
                items,
            };
            if (values.note) payload.note = values.note;
            try {
                await api('/stock-transfers', { method: 'POST', body: JSON.stringify(payload) });
                event.currentTarget.reset();
                renderTransferLines();
                await Promise.all([loadTransfers(), loadProducts()]);
                toast('Stock transfer completed.');
            } catch (error) {
                toast(error.message, true);
            }
        });
        $('#refresh-transfers').addEventListener('click', loadTransfers);
        $('#sales-table').addEventListener('click', (event) => {
            const printButton = event.target.closest('[data-print-sale]');
            const paymentButton = event.target.closest('[data-pay-sale]');
            const returnButton = event.target.closest('[data-return-sale]');
            const id = Number(printButton?.dataset.printSale || paymentButton?.dataset.paySale || returnButton?.dataset.returnSale);
            const sale = state.sales.find((item) => Number(item.id) === id);
            if (!sale) return;
            if (printButton) printSale(sale);
            else if (paymentButton) openPaymentDialog('sale', sale);
            else if (returnButton) openReturnDialog(sale);
        });
        $('#sales-pagination').addEventListener('click', (event) => {
            const button = event.target.closest('[data-sales-page]');
            if (button && !button.disabled) loadSales(Number(button.dataset.salesPage));
        });
        $('#sales-invoice-filter').addEventListener('input', () => {
            window.clearTimeout(state.salesSearchTimer);
            state.salesSearchTimer = window.setTimeout(() => loadSales(1), 250);
        });
        ['#sales-status-filter', '#sales-from-date', '#sales-to-date'].forEach((selector) => {
            $(selector).addEventListener('change', () => loadSales(1));
        });
        $('#reset-sales-filters').addEventListener('click', () => {
            $('#sales-invoice-filter').value = '';
            $('#sales-status-filter').value = '';
            $('#sales-from-date').value = '';
            $('#sales-to-date').value = '';
            loadSales(1);
        });
        $('#customers-table').addEventListener('click', async (event) => {
            const editButton = event.target.closest('[data-edit-customer]');
            const deleteButton = event.target.closest('[data-delete-customer]');
            const id = Number(editButton?.dataset.editCustomer || deleteButton?.dataset.deleteCustomer);
            const customer = state.customers.find((item) => Number(item.id) === id);
            if (!customer) return;
            if (editButton) {
                openSimpleEditor(`Edit · ${customer.name}`, `
                    <label>Name<input name="name" value="${escapeHtml(customer.name)}" required></label>
                    <label>Phone<input name="phone" value="${escapeHtml(customer.phone || '')}" required></label>
                    <label>Email<input name="email" type="email" value="${escapeHtml(customer.email || '')}"></label>
                    <label>Contact person<input name="contact_person" value="${escapeHtml(customer.contact_person || '')}"></label>
                    <label>Tax number<input name="tax_number" value="${escapeHtml(customer.tax_number || '')}"></label>
                    <label>Credit limit<input name="credit_limit" type="number" min="0" step="0.01" value="${Number(customer.credit_limit || 0)}"></label>
                    <label>Opening balance<input name="opening_balance" type="number" step="0.01" value="${Number(customer.opening_balance || 0)}"></label>`,
                'Save changes', async (values) => {
                    values.credit_limit = Number(values.credit_limit || 0);
                    values.opening_balance = Number(values.opening_balance || 0);
                    for (const key of ['email', 'contact_person', 'tax_number']) if (!values[key]) values[key] = null;
                    await api(`/customers/${id}`, { method: 'PATCH', body: JSON.stringify(values) });
                    closeDialog();
                    toast('Customer updated.');
                    await loadCustomers();
                });
            } else if (deleteButton) {
                await deleteResource(`/customers/${id}`, 'customer', () => loadCustomers());
            }
        });
        $('#expenses-table').addEventListener('click', async (event) => {
            const editButton = event.target.closest('[data-edit-expense]');
            const deleteButton = event.target.closest('[data-delete-expense]');
            const id = Number(editButton?.dataset.editExpense || deleteButton?.dataset.deleteExpense);
            const expense = state.expenses.find((item) => Number(item.id) === id);
            if (!expense) return;
            if (editButton) {
                openSimpleEditor(`Edit · ${expense.category}`, `
                    <label>Category<input name="category" value="${escapeHtml(expense.category)}" required></label>
                    <label>Amount<input name="amount" type="number" min="0.01" step="0.01" value="${Number(expense.amount)}" required></label>
                    <label>Spent at<input name="spent_at" type="date" value="${escapeHtml(String(expense.spent_at || '').slice(0, 10))}" required></label>
                    <label>Payment method<select name="payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select></label>
                    <label>Reference<input name="reference" value="${escapeHtml(expense.reference || '')}"></label>
                    <label>Note<input name="note" value="${escapeHtml(expense.note || '')}"></label>`,
                'Save changes', async (values) => {
                    values.amount = Number(values.amount);
                    for (const key of ['reference', 'note']) if (!values[key]) values[key] = null;
                    await api(`/expenses/${id}`, { method: 'PATCH', body: JSON.stringify(values) });
                    closeDialog();
                    toast('Expense updated.');
                    await loadExpenses();
                });
                $('#dialog-fields [name="payment_method"]').value = expense.payment_method || 'cash';
            } else if (deleteButton) {
                await deleteResource(`/expenses/${id}`, 'expense', loadExpenses);
            }
        });
        $('#action-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!state.dialogSubmit) return;
            $('#dialog-submit').disabled = true;
            $('#dialog-error').textContent = '';
            try {
                await state.dialogSubmit(formObject(event.currentTarget));
            } catch (error) {
                $('#dialog-error').textContent = error.message;
            } finally {
                $('#dialog-submit').disabled = false;
            }
        });
        $$('#action-dialog [data-dialog-close]').forEach((button) => button.addEventListener('click', closeDialog));
        $('#dialog-fields').addEventListener('change', (event) => {
            if (event.target.name === 'sale_item_id') {
                const selected = event.target.selectedOptions[0];
                const quantity = $('#dialog-fields [name="quantity"]');
                quantity.max = selected.dataset.maxQuantity;
                if (Number(quantity.value) > Number(quantity.max)) quantity.value = quantity.max;
            }
            if (event.target.name === 'variant_id') {
                const product = state.inventoryProducts.find((item) => item.variants?.some((variant) => Number(variant.id) === Number(event.target.value)));
                const variant = product?.variants.find((item) => Number(item.id) === Number(event.target.value));
                $('#dialog-fields .stock-current').textContent = String(variant?.stock?.quantity ?? product?.stock?.quantity ?? 0);
            }
            if (event.target.name === 'direction') {
                $('#dialog-fields').querySelectorAll('[data-adjustment-batch-field]').forEach((field) => {
                    field.classList.toggle('hidden', event.target.value !== 'add');
                });
            }
        });
        $('#product-search').addEventListener('input', loadProducts);
        $('#refresh-reports').addEventListener('click', loadReports);
        $('#branch-switcher').addEventListener('change', (event) => {
            state.branchId = Number(event.target.value);
            const branch = state.branches.find((item) => item.id === state.branchId);
            $('#branch-label').textContent = branch?.name || 'Select a branch';
            state.session = null;
            loadCashData();
            showView($('.page-view.active')?.id.replace('view-', '') || 'overview');
        });
    }

    async function loadBranches() {
        if (state.user.role !== 'super-admin' || !permissions.has('manage branches')) return;
        const { data } = await api('/branches');
        state.branches = data || [];
        const selector = $('#branch-switcher');
        selector.classList.remove('hidden');
        selector.innerHTML = state.branches.map((branch) => `<option value="${branch.id}">${escapeHtml(branch.name)}</option>`).join('');
        if (!state.branches.length) {
            state.branchId = null;
            $('#branch-label').textContent = 'No active branches';
            return;
        }
        state.branchId = Number(state.user.branch?.id || state.branches[0].id);
        selector.value = String(state.branchId);
        $('#branch-label').textContent = state.branches.find((branch) => branch.id === state.branchId)?.name || state.branches[0].name;
    }

    async function init() {
        setUpEvents();
        renderCart();
        try {
            const { user } = await api('/me');
            setUser(user);
            await loadBranches();
            const sessionData = await api('/cash-register-sessions').catch(() => null);
            const openSession = sessionData?.data?.data?.find((session) => session.status === 'open' && (!state.branchId || Number(session.cash_register?.branch_id) === Number(state.branchId)));
            if (openSession) state.session = openSession;
            renderSessionState();
            await loadOverview();
        } catch (error) {
            if (!error.message.includes('expired')) toast(error.message, true);
        }
    }

    init();
})();
