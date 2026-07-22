# relax-wear

# Create OTO (One Time Offer): OTO 1, 2, 3 sequentially with offers on different product

To deliver this system successfully without premium plugins, you must build a custom WordPress plugin that bridges WooCommerce data structures with your frontend templates.

Here is the deep-dive technical breakdown of how each **Functional Requirement (FR)** must be architected in your backend code.

---

## Part 1: Funnel Logic, Bundles & Analytics (3.5)

### FR-5.1: OTO 1, 2, 3 – প্রতিটি ভিন্ন product, কেনার পরপর ক্রমান্বয়ে (Sequential Multi-Product OTOs)

- **Detailed Logic:** As soon as the customer clicks "Place Order" on the checkout page, the standard WooCommerce redirect loop must be intercepted. The order is created in the database with a status of `pending` or `processing`. Instead of seeing the default Thank You page, the system checks the order contents and routes the user to custom templates for OTO 1, OTO 2, and OTO 3 in strict sequential order.
- **Backend Architecture:** You will register custom endpoint query variables using `add_rewrite_endpoint()`. When a user accepts or declines OTO 1, the script processes that choice via AJAX, updates the database, and returns the URL for OTO 2.
- **Data Flow:**

$$\text{Core Checkout} \longrightarrow \text{Page: OTO 1} \longrightarrow \text{Page: OTO 2} \longrightarrow \text{Page: OTO 3} \longrightarrow \text{Final Receipt Page}$$

### FR-5.2: Bundle offer – একাধিক product একত্রে বিশেষ মূল্যে (Product Bundles at Special Pricing)

- **Detailed Logic:** You need to present multiple products packaged together as a single offer at a discounted price (e.g., Buy Product A + Product B together for 30% off).
- **Backend Architecture:** Rather than using a premium product-bundling plugin, you can handle this natively using a hidden WooCommerce product type or by writing a function that programmatically manipulates the cart/order array.
- When the bundle offer is accepted, your backend code uses a loop to add each individual product ID to the order using `$order->add_product()`. To apply the "special price," you apply a negative line-item discount fee using `WC_Order_Item_Fee` to offset the total cost down to the promotional bundled rate.

### FR-5.3: OTO প্রত্যাখ্যান করলে downsell অফার দেখানো (Downsell Conditional Routing)

- **Detailed Logic:** This introduces **behavioral branching**. If a user clicks the "No Thanks" (Decline) button on any OTO page, the system must not break the funnel. It must instantly display a cheaper or alternative offer (Downsell) to recover the sales opportunity before moving them forward.
- **Backend Architecture:** Your AJAX handler must receive a `choice` parameter (`accept` or `decline`).

```php
// Logic Tree mapping inside your AJAX handler
if ($current_step === "oto1" && $choice === "decline") {
  wp_send_json_success([
    "next_url" => site_url("/checkout-funnel/?step=downsell1&oid=" . $order_id),
  ]);
}
```

If they decline OTO 1, they see Downsell 1. Whether they accept or decline Downsell 1, the system passes them back into the main pipeline by sending them to OTO 2 next.

### FR-5.4: প্রতিটি OTO/downsell-এর accept ও decline পথ ট্র্যাকযোগ্য (Granular Funnel Path Analytics)

- **Detailed Logic:** Every click—whether it is an acceptance or a rejection—must be tracked and stored. This allows the store owner to calculate conversion rates for individual funnel steps (e.g., "What percentage of people decline OTO 1 but buy Downsell 1?").
- **Backend Architecture:** You will create a custom database table upon plugin activation, or utilize WooCommerce order meta fields (`update_post_meta`).
- Every time your AJAX processing endpoint is hit, it takes the `step`, `action_status` (accept/decline), and `order_id`, logging a timestamped row into the database _before_ it handles the product addition or redirection. This gives you perfectly clean data independent of external Google Analytics scripts that ad-blockers might interrupt.

---

## Part 2: One-Click Upsell Behavior & Gateways (3.7)

### FR-7.1: COD অর্ডারে true one-click OTO (পুনরায় তথ্য ছাড়াই add) (One-Click Setup for Cash on Delivery)

- **Detailed Logic:** For Cash on Delivery (COD) orders, the friction must be absolutely zero. Because no electronic payment clearance is occurring live, clicking "Accept" must seamlessly edit the order details in the backend database instantly without requesting address verification or reloading checkout forms.
- **Backend Architecture:** When the AJAX hook receives an "accept" for a COD order, it instantiates the WooCommerce order object, appends the new product line item, recalculates taxes and totals using `$order->calculate_totals()`, adds an order note detailing the upsell, and immediately gives the green light to push the browser to the next step.

### FR-7.2: Online gateway-তে accept/decline ফ্লো (প্রতি OTO-তে দ্রুত পেমেন্ট সেশন) (Fast Payment Session Processing)

- **Detailed Logic:** If a user pays via an online credit card gateway or mobile wallet, accepting an OTO must execute a fast, secure payment capture without breaking the active browsing session or forcing a total checkout re-fill.
- **Backend Architecture:** You must leverage tokenized payment architectures. For standard cards, when the initial checkout occurs, ensure your gateway integration creates a secure customer payment profile token (`WC_Payment_Token`). When the OTO is accepted, your script uses that background customer token to invoke a secondary charge API request (`process_payment`) programmatically behind the scenes, ensuring the browser window never leaves the upsell landing page.

### FR-7.3: bKash tokenized/agreement API দিয়ে online true one-click (Phase 2 - bKash Agreement Integration)

- **Detailed Logic:** This is your most critical regional payment constraint. Standard bKash payment processing requires a PIN and OTP verification on every single transaction, which ruins the "one-click" experience. To bypass this, you must build an integration with **bKash's Tokenized/Agreement API**.
- **Backend Architecture:**

1. During the **initial checkout**, instead of a standard payment request, your code initializes a `Create Agreement` call alongside the `Create Payment` call.
2. The customer types their bKash PIN/OTP once to authorize the initial order.
3. bKash processes the payment and registers a persistent, unique `agreementID` linked to that customer's wallet. Your backend saves this token securely.
4. When the user hits "Accept" on OTO 1, OTO 2, or OTO 3, your server sends a silent background API payload directly to bKash using the stored `agreementID` and the new OTO amount. bKash charges the customer's wallet **instantly without requesting another PIN or OTP input**, achieving a true enterprise-grade one-click funnel experience.

---

FR-5.1 — OTO 1, 2, 3, sequential, different products (Must)

### Implementation Details:

- Each OTO step needs:

  - Trigger product(s)
  - Offer product
  - Position (1 | 2 | 3)
  - Offer price | Discount
  - Offer page template


