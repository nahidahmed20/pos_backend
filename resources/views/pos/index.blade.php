<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Workspace | Aamar POS</title>
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}">
</head>
<body class="workspace-page">
    <div class="workspace-shell">
        <aside class="sidebar">
            <a class="sidebar-brand" href="{{ route('pos.index') }}">
                <span class="brand-mark brand-mark-small">A</span>
                <span><strong>Aamar</strong><small>POS WORKSPACE</small></span>
            </a>
            <p class="nav-caption">WORKSPACE</p>
            <nav id="workspace-nav" class="workspace-nav">
                <button class="nav-link active" data-view="overview"><span>◈</span> Overview</button>
                <button class="nav-link" data-view="pos" data-permission="manage pos"><span>▣</span> Point of sale</button>
                <button class="nav-link" data-view="cash" data-permission="manage pos"><span>৳</span> Cash register</button>
                <button class="nav-link" data-view="products" data-permission="manage products"><span>□</span> Products</button>
                <button class="nav-link" data-view="transfers" data-permission="manage products"><span>⇄</span> Stock transfers</button>
                <button class="nav-link" data-view="categories" data-permission="manage products"><span>▦</span> Categories</button>
                <button class="nav-link" data-view="customers" data-permission="manage pos"><span>♙</span> Customers</button>
                <button class="nav-link" data-view="sales" data-permission="manage pos"><span>↗</span> Sales</button>
                <button class="nav-link" data-view="suppliers" data-permission="manage purchases"><span>♧</span> Suppliers</button>
                <button class="nav-link" data-view="purchases" data-permission="manage purchases"><span>⇣</span> Purchases</button>
                <button class="nav-link" data-view="expenses" data-permission="manage expenses"><span>↗</span> Expenses</button>
                <button class="nav-link" data-view="reports" data-permission="view reports"><span>▤</span> Reports</button>
            </nav>
            <div class="sidebar-bottom">
                <div class="support-note"><span class="status-dot"></span><span>Workspace is ready<small>Branch data stays private</small></span></div>
                <button id="logout-button" class="logout-button">↪ <span>Sign out</span></button>
            </div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <div class="mobile-brand"><span class="brand-mark brand-mark-small">A</span><strong>Aamar POS</strong></div>
                <div class="topbar-context"><select id="branch-switcher" class="branch-switcher hidden" aria-label="Select branch"></select><span id="branch-label">Loading branch…</span><span class="context-divider">/</span><strong id="page-label">Overview</strong></div>
                <div class="user-menu">
                    <span class="avatar" id="user-avatar">A</span>
                    <span class="user-summary"><strong id="user-name">Loading…</strong><small id="user-role">Team member</small></span>
                </div>
            </header>

            <section id="view-overview" class="page-view active">
                <div class="page-heading">
                    <div><p class="eyebrow">YOUR BUSINESS AT A GLANCE</p><h1>Good day, <span id="greeting-name">there</span></h1><p class="muted">Here’s what’s happening across your workspace.</p></div>
                    <button class="button button-primary" data-open-view="pos" data-permission="manage pos">＋ New sale</button>
                </div>
                <div id="stats-grid" class="stats-grid"></div>
                <div class="overview-grid">
                    <article class="panel welcome-panel">
                        <div class="panel-icon">▣</div>
                        <div data-permission="manage pos"><p class="eyebrow">READY WHEN YOU ARE</p><h2>Start a sale</h2><p class="muted">Search products, add them to the cart and complete checkout in a few steps.</p><button class="button button-primary" data-open-view="pos">Open point of sale <span>→</span></button></div>
                    </article>
                    <article class="panel quick-panel">
                        <div class="panel-header"><div><p class="eyebrow">QUICK ACCESS</p><h2>Your workspace</h2></div></div>
                        <div class="quick-links">
                            <button data-open-view="cash"><span class="quick-icon peach">৳</span><span><strong>Cash register</strong><small>Open or close a register session</small></span><b>→</b></button>
                            <button data-open-view="products" data-permission="manage products"><span class="quick-icon blue">□</span><span><strong>Product catalog</strong><small>Check products and available stock</small></span><b>→</b></button>
                            <button data-open-view="customers" data-permission="manage pos"><span class="quick-icon green">♙</span><span><strong>Customers</strong><small>View your branch customers</small></span><b>→</b></button>
                        </div>
                    </article>
                </div>
            </section>

            <section id="view-pos" class="page-view">
                <div class="page-heading compact-heading"><div><p class="eyebrow">CHECKOUT</p><h1>Point of sale</h1><p class="muted">Build a cart and record the payment.</p></div><span id="pos-session-status" class="pill pill-muted">No open register session</span></div>
                <div class="pos-layout">
                    <section class="panel catalog-panel">
                        <div class="catalog-toolbar"><div><p class="eyebrow">PRODUCT CATALOG</p><h2>Find an item</h2></div><label class="search-field"><span>⌕</span><input id="catalog-search" type="search" placeholder="Name, SKU or barcode"></label></div>
                        <div id="catalog-grid" class="catalog-grid"><div class="empty-state">Loading products…</div></div>
                        <div id="catalog-pagination" class="pagination"></div>
                    </section>
                    <section class="panel cart-panel">
                        <div class="panel-header"><div><p class="eyebrow">CURRENT ORDER</p><h2>Cart <span id="cart-count" class="count-badge">0</span></h2></div><button id="clear-cart" class="text-button">Clear</button></div>
                        <div id="cart-items" class="cart-items"><div class="empty-state">Choose a product to get started.</div></div>
                        <div class="cart-checkout">
                            <label for="sale-customer">Customer <span class="optional">Optional</span></label>
                            <select id="sale-customer"><option value="">Walk-in customer</option></select>
                            <label for="sale-discount">Discount</label>
                            <div class="money-input"><span>৳</span><input id="sale-discount" type="number" min="0" step="0.01" value="0"></div>
                            <label for="sale-tax">Tax amount</label>
                            <div class="money-input"><span>৳</span><input id="sale-tax" type="number" min="0" step="0.01" value="0"></div>
                            <div class="total-line"><span>Subtotal</span><strong id="sale-subtotal">৳0.00</strong></div>
                            <div class="total-line"><span>Total</span><strong id="sale-total">৳0.00</strong></div>
                            <label for="sale-paid">Paid now</label>
                            <div class="money-input"><span>৳</span><input id="sale-paid" type="number" min="0" step="0.01" value="0"></div>
                            <div class="checkout-row">
                                <label for="sale-method">Method</label>
                                <select id="sale-method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option></select>
                            </div>
                            <button id="complete-sale" class="button button-primary button-full" disabled>Complete sale</button>
                            <p id="sale-message" class="form-message" role="status"></p>
                        </div>
                    </section>
                </div>
            </section>

            <section id="view-cash" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">CASH CONTROL</p><h1>Cash register</h1><p class="muted">Open a shift, record cash movements and reconcile at close.</p></div></div>
                <div class="cash-grid">
                    <article class="panel">
                        <div class="panel-header"><div><p class="eyebrow">REGISTER SESSION</p><h2 id="session-heading">Open a register</h2></div><span id="cash-status" class="pill pill-muted">Closed</span></div>
                        <div id="open-session-form">
                            <label for="register-select">Cash register</label><select id="register-select"></select>
                            <label for="opening-balance">Opening cash</label><div class="money-input"><span>৳</span><input id="opening-balance" type="number" min="0" step="0.01" value="0"></div>
                            <label for="opening-note">Note <span class="optional">Optional</span></label><input id="opening-note" type="text" placeholder="Opening cash count">
                            <button id="open-session" class="button button-primary button-full">Open session</button>
                        </div>
                        <div id="active-session-panel" class="hidden">
                            <div class="session-balance"><small>OPENING CASH</small><strong id="session-opening">৳0.00</strong></div>
                            <div class="movement-form">
                                <h3>Record cash movement</h3>
                                <div class="checkout-row"><select id="movement-direction"><option value="in">Cash in</option><option value="out">Cash out</option></select><div class="money-input"><span>৳</span><input id="movement-amount" type="number" min="0.01" step="0.01" placeholder="0.00"></div></div>
                                <input id="movement-description" type="text" placeholder="Reason for movement">
                                <button id="record-movement" class="button button-secondary button-full">Save movement</button>
                            </div>
                            <div class="movement-form close-form"><h3>Close and reconcile</h3><label for="closing-balance">Counted closing cash</label><div class="money-input"><span>৳</span><input id="closing-balance" type="number" min="0" step="0.01" placeholder="0.00"></div><input id="closing-note" type="text" placeholder="Closing note (optional)"><button id="close-session" class="button button-dark button-full">Close session</button></div>
                        </div>
                        <p id="cash-message" class="form-message" role="status"></p>
                    </article>
                    <article class="panel">
                        <div class="panel-header"><div><p class="eyebrow">REGISTER SETUP</p><h2>Branch registers</h2></div></div>
                        <div id="register-list" class="data-list"><div class="empty-state">Loading registers…</div></div>
                        <form id="register-form" class="inline-form hidden" data-permission="manage branches">
                            <h3>Add a cash register</h3><input name="name" placeholder="Register name" required><input name="code" placeholder="Code" required><input name="location" placeholder="Location (optional)"><button class="button button-secondary button-full" type="submit">Add register</button>
                        </form>
                    </article>
                </div>
                <article class="panel table-panel"><div class="panel-header"><div><p class="eyebrow">RECENT HISTORY</p><h2>My sessions</h2></div><button id="refresh-sessions" class="text-button">Refresh</button></div><div id="session-history" class="data-list"><div class="empty-state">Loading sessions…</div></div></article>
            </section>

            <section id="view-products" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">INVENTORY</p><h1>Products</h1><p class="muted">Create products and browse branch stock.</p></div><div class="heading-actions"><label class="search-field"><span>⌕</span><input id="product-search" type="search" placeholder="Search products"></label><button id="toggle-product-form" class="button button-primary">＋ Add product</button></div></div>
                <article id="product-form-panel" class="panel form-panel hidden">
                    <form id="product-form" class="form-grid">
                        <label>Name<input name="name" required maxlength="255"></label>
                        <label>SKU<input name="sku" required maxlength="255"></label>
                        <label>Category<select name="category_id" id="product-category" required></select></label>
                        <label>Barcode<input name="barcode"></label>
                        <label>Purchase price<input name="purchase_price" type="number" min="0" step="0.01" value="0"></label>
                        <label>Selling price<input name="selling_price" type="number" min="0" step="0.01" required></label>
                        <label>Unit<input name="unit" value="piece" required></label>
                        <label>Tax rate (%)<input name="tax_rate" type="number" min="0" max="100" step="0.01" value="0"></label>
                        <label>Expiry tracking<select name="has_expiry"><option value="false">Not tracked</option><option value="true">Track batches and expiry</option></select></label>
                        <button class="button button-primary" type="submit">Save product</button>
                    </form>
                </article>
                <article class="panel table-panel"><div id="products-table" class="data-list"><div class="empty-state">Loading products…</div></div></article>
            </section>

            <section id="view-transfers" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">INVENTORY MOVEMENT</p><h1>Stock transfers</h1><p class="muted">Transfer stock from this branch to another branch. Each product can appear once per transfer.</p></div></div>
                <article class="panel form-panel">
                    <form id="transfer-form" class="form-grid">
                        <label>Source branch<input id="transfer-source" value="Loading…" readonly></label>
                        <label>Destination branch<select name="to_branch_id" id="transfer-destination" required></select></label>
                        <div class="form-wide">
                            <label>Items</label>
                            <div id="transfer-lines" class="purchase-lines"></div>
                            <button id="add-transfer-line" class="button button-secondary" type="button">＋ Add another item</button>
                        </div>
                        <label class="form-wide">Note<input name="note" maxlength="255"></label>
                        <button class="button button-primary" type="submit">Transfer stock</button>
                    </form>
                </article>
                <article class="panel table-panel"><div class="panel-header"><div><p class="eyebrow">TRANSFER HISTORY</p><h2>Recent branch movements</h2></div><button id="refresh-transfers" class="text-button">Refresh</button></div><div id="transfers-table" class="data-list"><div class="empty-state">Loading transfers…</div></div></article>
            </section>

            <section id="view-categories" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">PRODUCT ORGANIZATION</p><h1>Categories</h1><p class="muted">Group products for faster catalog browsing.</p></div><button id="toggle-category-form" class="button button-primary">＋ Add category</button></div>
                <article id="category-form-panel" class="panel form-panel hidden"><form id="category-form" class="form-grid"><label>Name<input name="name" required></label><label>Slug<input name="slug"></label><label class="form-wide">Description<textarea name="description" rows="2"></textarea></label><button class="button button-primary" type="submit">Save category</button></form></article>
                <article class="panel table-panel"><div id="categories-table" class="data-list"><div class="empty-state">Loading categories…</div></div></article>
            </section>

            <section id="view-customers" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">CUSTOMER DIRECTORY</p><h1>Customers</h1><p class="muted">Keep customer contact and account details close.</p></div><button id="toggle-customer-form" class="button button-primary">＋ Add customer</button></div>
                <article id="customer-form-panel" class="panel form-panel hidden"><form id="customer-form" class="form-grid"><label>Name<input name="name" required></label><label>Phone<input name="phone" required></label><label>Email<input name="email" type="email"></label><label>Contact person<input name="contact_person"></label><label>Tax number<input name="tax_number"></label><label>Credit limit<input name="credit_limit" type="number" min="0" step="0.01" value="0"></label><label class="form-wide">Address<textarea name="address" rows="2"></textarea></label><button class="button button-primary" type="submit">Save customer</button></form></article>
                <article class="panel table-panel"><div id="customers-table" class="data-list"><div class="empty-state">Loading customers…</div></div></article>
            </section>

            <section id="view-purchases" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">SUPPLIER ORDERS</p><h1>Purchases</h1><p class="muted">Receive stock, record supplier invoices and track balances.</p></div><button id="toggle-purchase-form" class="button button-primary">＋ New purchase</button></div>
                <article id="purchase-form-panel" class="panel form-panel hidden">
                    <form id="purchase-form" class="form-grid">
                        <label>Supplier<select name="supplier_id" id="purchase-supplier" required></select></label>
                        <label>Supplier invoice<input name="invoice_number" required maxlength="100"></label>
                        <label>Purchase date<input name="purchased_at" type="date" required></label>
                        <div class="form-wide">
                            <label>Purchase items</label>
                            <div id="purchase-lines" class="purchase-lines"></div>
                            <button id="add-purchase-line" class="button button-secondary" type="button">＋ Add another item</button>
                        </div>
                        <label>Initial payment<input name="paid_amount" type="number" min="0" step="0.01" value="0"></label>
                        <label>Payment method<select name="payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select></label>
                        <label>Discount<input name="discount" type="number" min="0" step="0.01" value="0"></label>
                        <label>Tax<input name="tax" type="number" min="0" step="0.01" value="0"></label>
                        <label class="form-wide">Note<textarea name="note" rows="2"></textarea></label>
                        <button class="button button-primary" type="submit">Receive purchase</button>
                    </form>
                    <p class="muted form-hint">Record batch and expiry details on each line when the product requires them.</p>
                </article>
                <article class="panel table-panel"><div id="purchases-table" class="data-list"><div class="empty-state">Loading purchases…</div></div></article>
            </section>

            <section id="view-suppliers" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">SUPPLIER DIRECTORY</p><h1>Suppliers</h1><p class="muted">Maintain supplier contacts and credit details.</p></div><button id="toggle-supplier-form" class="button button-primary">＋ Add supplier</button></div>
                <article id="supplier-form-panel" class="panel form-panel hidden"><form id="supplier-form" class="form-grid"><label>Name<input name="name" required></label><label>Phone<input name="phone"></label><label>Email<input name="email" type="email"></label><label>Contact person<input name="contact_person"></label><label>Tax number<input name="tax_number"></label><label>Credit limit<input name="credit_limit" type="number" min="0" step="0.01" value="0"></label><label class="form-wide">Address<textarea name="address" rows="2"></textarea></label><button class="button button-primary" type="submit">Save supplier</button></form></article>
                <article class="panel table-panel"><div id="suppliers-table" class="data-list"><div class="empty-state">Loading suppliers…</div></div></article>
            </section>

            <section id="view-sales" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">TRANSACTION HISTORY</p><h1>Sales</h1><p class="muted">Review invoices and payment balances for your branch.</p></div></div>
                <article class="panel sales-filters">
                    <label>Invoice<input id="sales-invoice-filter" type="search" placeholder="Search invoice number"></label>
                    <label>Payment status<select id="sales-status-filter"><option value="">All statuses</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="paid">Paid</option></select></label>
                    <label>From<input id="sales-from-date" type="date"></label>
                    <label>To<input id="sales-to-date" type="date"></label>
                    <button id="reset-sales-filters" class="button button-secondary" type="button">Clear filters</button>
                </article>
                <article class="panel table-panel"><div id="sales-table" class="data-list"><div class="empty-state">Loading sales…</div></div></article>
                <div id="sales-pagination" class="pagination"></div>
            </section>

            <section id="view-expenses" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">BRANCH COSTS</p><h1>Expenses</h1><p class="muted">Record and review branch spending.</p></div><button id="toggle-expense-form" class="button button-primary">＋ Add expense</button></div>
                <article id="expense-form-panel" class="panel form-panel hidden"><form id="expense-form" class="form-grid"><label>Category<input name="category" required></label><label>Amount<input name="amount" type="number" min="0.01" step="0.01" required></label><label>Spent at<input name="spent_at" type="date" required></label><label>Payment method<select name="payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_banking">Mobile banking</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select></label><label>Reference<input name="reference"></label><label>Receipt path<input name="receipt_path"></label><label class="form-wide">Note<textarea name="note" rows="2"></textarea></label><button class="button button-primary" type="submit">Save expense</button></form></article>
                <article class="panel table-panel"><div id="expenses-table" class="data-list"><div class="empty-state">Loading expenses…</div></div></article>
            </section>

            <section id="view-reports" class="page-view">
                <div class="page-heading"><div><p class="eyebrow">BUSINESS PERFORMANCE</p><h1>Reports</h1><p class="muted">A quick summary of sales, purchases and expenses.</p></div><button id="refresh-reports" class="button button-secondary">Refresh</button></div>
                <div id="report-stats" class="stats-grid"></div>
            </section>
        </main>
    </div>
    <div id="toast" class="toast" role="status"></div>
    <dialog id="action-dialog" class="action-dialog"><form id="action-form" method="dialog"><div class="panel-header"><div><p class="eyebrow">UPDATE RECORD</p><h2 id="dialog-title"></h2></div><button class="dialog-close" type="button" data-dialog-close aria-label="Close">×</button></div><div id="dialog-fields"></div><p id="dialog-error" class="form-message" role="alert"></p><div class="dialog-actions"><button class="button button-secondary" type="button" data-dialog-close>Cancel</button><button class="button button-primary" id="dialog-submit" type="submit">Save</button></div></form></dialog>
    <script src="{{ asset('js/pos.js') }}" defer></script>
</body>
</html>
