public function createOrderNew(Request $request)
 {
    $cart_contents = [];
    $validator = Validator::make($request->all(), ["cart_id" => 'required', 'paymentId' => 'required',"orderId" => 'required',"signature" => 'required']);

    if ($validator->fails()) {
        return response()
        ->json(['success' => false, "message" => 'Some fields are missing are Incorrect! Retry after correcting.', 'errors' => $validator->errors()
            ->all() , 'data' => null], 200);
    } else {
     $server_signature =  hash_hmac('sha256', $request->orderId ."|". $request->paymentId, env('RAZORPAY_PAYMENT_ID'));

       // if ($request->signature == $server_signature) {
     $users_primary_address = UserAddress::where('user_id', Auth::id())->where('type', '=', 1)
     ->first();
     if (is_null($users_primary_address)) {
        return response()->json(['success' => false, "message" => 'User doesnt have a primary address in profile.', 'errors' => null, 'data' => null], 200);
    }
    $cart_to_find = Cart::where('cart_id', $request->cart_id)
    ->first();
    if (is_null($cart_to_find)) {
        return response()->json(['success' => false, "message" => 'Cart doesnt exist with the given cart ID.', 'errors' => null, 'data' => null], 200);
    }
    $cart_contents = CartItem::where('carts_id', $cart_to_find->id)
    ->get();
    if (!count($cart_contents) > 0) {
        return response()->json(['success' => false, "message" => 'Cart seems to be empty.Add items to cart and try again', 'errors' => null, 'data' => null], 200);
    }
    // }

        //create a new order on main order table

    $new_order_summary = new ListingOrderSummary;
    $new_order_summary->customer_id = Auth::id();
    $now = Carbon::now();
    $new_order_summary->order_summary_id = 'ABLESUM' . $now->year . $now->month . Auth::id() . rand(0, 99999);
    $new_order_summary->save();


        //loop through cart items and create new order_detail row

    $sp_id_array_by_service = [];
    $sp_id_array_by_product = [];
    $sp_id_array_by_tour = [];
    $sp_id_array_by_paratransit = [];
    $orders_collection_array = [];
    $service_to_find = null;
    $product_to_find = null;
    $tour_to_find = null;
    $paratransit_to_find = null;

    $order_address = null;
    $sp_address = null;
    $listing_to_find = null;
    $order_collection = [];

    foreach ($cart_contents as $key => $value) {
            //create new order
        $new_order = new ListingOrder();
        $new_order->customer_id = Auth::id();
        $new_order->listing_order_summary_id = $new_order_summary->id;
        $now = Carbon::now();
        $new_order->order_number = 'ABLE' . $now->year . $now->month . Auth::id() . rand(0, 99999);
        $new_order->total = 0;
        $new_order->status = 15;
        $new_order->save();
        array_push($order_collection, $new_order->order_number);
            //end create order

        if ($cart_contents[$key]->listing_service_id) {
            $service_to_find = ListingService::find($cart_contents[$key]->listing_service_id);
            array_push($sp_id_array_by_service, [
                $service_to_find->id,
                $service_to_find->service_provider_id
            ]);
        }

        if ($cart_contents[$key]->listing_product_id) {
            $product_to_find = ListingProduct::find($cart_contents[$key]->listing_product_id);
            array_push($sp_id_array_by_product, [
                $product_to_find->id,
                $product_to_find->service_provider_id,
                $cart_contents[$key]->listing_product_variation_id
            ]);
        }

        if ($cart_contents[$key]->listing_tour_id) {
            $tour_to_find = ListingTour::find($cart_contents[$key]->listing_tour_id);
            array_push($sp_id_array_by_tour, [
                $tour_to_find->id,
                $tour_to_find->service_provider_id,
                $cart_contents[$key]->listing_location_id
            ]);
        }

        if ($cart_contents[$key]->listing_paratransit_id) {
            $paratransit_to_find = ListingParaTransit::find($cart_contents[$key]->listing_paratransit_id);
            array_push($sp_id_array_by_paratransit, [
                $paratransit_to_find->id,
                $paratransit_to_find->service_provider_id,
                $cart_contents[$key]->listing_location_id
            ]);
        }

        if ($product_to_find) {
            $listing_to_find = Listing::where('listing_product_id', $cart_contents[$key]->listing_product_id)
            ->first();
            $product_to_find = ListingProduct::find($listing_to_find->listing_product_id);
            $sp_address = UserAddress::where('user_id', $product_to_find->service_provider_id)->first();

            if (is_null($sp_address)) {
                return response()->json(['success' => false, "message" => 'Provider deosnt have a primary address.Order cannot continue.', 'errors' => null, 'data' => null], 200);
            }
                // service_provider_id
            $location_of_product = ListingLocation::where('listing_product_id', $product_to_find->id)
            ->first();
                // $order_address = $location_of_product->address;
            $new_order->service_provider_id = $listing_to_find->user_id;
            $new_order->listing_id = $listing_to_find->id;

                // $new_order->product_variation_id = $sp_id_array_by_product[0][2];
            $new_order->service_type = 2;
            $new_order->service_provider_id = $sp_id_array_by_product[0][1];

            $cgst = ($product_to_find->pricing / 100) * 9;
            $sgst = ($product_to_find->pricing / 100) * 9;

            $new_order->total = $product_to_find->pricing + $cgst + $sgst;
            $new_order->save();

            $new_order_invoice = new OrderInvoice();
            $new_order_invoice->order_id = $new_order->id;
             $new_order_invoice->listing_order_summary_id = $new_order_summary->id;
            $new_order_invoice->total_invoice_amount = $new_order->total;
            $new_order_invoice->discounted_amount = 0;
            $new_order_invoice->tax = $cgst + $sgst;
            $new_order_invoice->paid_amount = $new_order->total;
            $new_order_invoice->status = 0;
            $new_order_invoice->save();

            $new_order_detail = new ListingOrderDetail();
            $new_order_detail->listing_orders_id = $new_order->id;
            $new_order_detail->listing_order_summary_id = $new_order_summary->id;
            $new_order_detail->listing_product_id = $cart_contents[$key]->listing_product_id;
            $new_order_detail->product_variation_id = $cart_contents[$key]->listing_product_variation_id;
            $new_order_detail->service_type = 2;
                // $new_order_detail->address_of_service = $sp_address->address;
            $new_order_detail->qty = $cart_contents[$key]->listing_product_qty;
            $new_order_detail->amount = (float)$product_to_find->pricing;
            $new_order_detail->total_price = (float)$new_order_detail->amount + $cgst + $sgst;
            $new_order_detail->save();
        }
        if ($service_to_find) {
            $new_order->service_type = 1;
            $new_order->service_provider_id = $sp_id_array_by_service[0][1];

            if ($service_to_find) {
                $listing_to_find = Listing::where('listing_service_id', $cart_contents[$key]->listing_service_id)
                ->first();
                $service_to_find = ListingService::find($listing_to_find->listing_service_id);
                $location_of_service = ListingLocation::where('listing_service_id', $service_to_find->id)
                ->first();
                if (is_null($location_of_service)) {
                    return response()
                    ->json(['success' => false, "message" => 'Listing location not found,Add from provider', 'data' => null, "errors" => null], 200);
                }
                $order_address = $location_of_service->address;
                $new_order->service_provider_id = $listing_to_find->user_id;
                $new_order->listing_id = $listing_to_find->id;
            }

            $cgst = ($service_to_find->pricing / 100) * 9;
            $sgst = ($service_to_find->pricing / 100) * 9;

            $new_order->total = $service_to_find->pricing + $cgst + $sgst;
            $new_order->save();

            $new_order_invoice = new OrderInvoice();
            $new_order_invoice->order_id = $new_order->id;
            $new_order_invoice->listing_order_summary_id = $new_order_summary->id;
            $new_order_invoice->total_invoice_amount = $new_order->total;
            $new_order_invoice->discounted_amount = 0;
            $new_order_invoice->tax = $cgst + $sgst;
            $new_order_invoice->paid_amount = $new_order->total;
            $new_order_invoice->status = 0;
            $new_order_invoice->save();

            $new_order_detail = new ListingOrderDetail();
            $new_order_detail->listing_orders_id = $new_order->id;
            $new_order_detail->listing_order_summary_id = $new_order_summary->id;
            $new_order_detail->listing_service_detail_id = $cart_contents[$key]->listing_service_slot_id;
            $new_order_detail->listing_service_id = $service_to_find->id;
            $new_order_detail->service_type = $request->service_type ? $request->service_type : null;
            $new_order_detail->address_of_service = $order_address;
            $new_order_detail->qty = $request->qty ? $request->qty : null;
            $new_order_detail->amount = (float)$service_to_find->pricing;
            $new_order_detail->total_price = (float)$new_order_detail->amount + $cgst + $sgst;
            $new_order_detail->save();

            $listing_service_detail = ListingServiceDetail::find($cart_contents[$key]->listing_service_slot_id);
            if($listing_service_detail)
            {
                $listing_service_detail->is_booked = true;
                $listing_service_detail->user_id = Auth::id();
                $listing_service_detail->save();
            }

        }
        if ($tour_to_find) {
            $new_order->service_type = 3;
            $new_order->service_provider_id = $sp_id_array_by_tour[0][1];

            if ($tour_to_find) {
                $listing_to_find = Listing::where('listing_tour_id', $cart_contents[$key]->listing_tour_id)
                ->first();
                $tour_to_find = ListingTour::find($listing_to_find->listing_tour_id);
                $tour_to_find->total_booked_count = $tour_to_find->total_booked_count + $cart_contents[$key]->listing_tour_travellers_count;
                $tour_to_find->save();
                $location_of_service = ListingLocation::where('listing_tour_id', $tour_to_find->id)
                ->first();
                if (is_null($location_of_service)) {
                    $sp_address  = UserAddress::where('user_id', $tour_to_find->service_provider_id)->first();
                    if (is_null($sp_address)) {
                        return response()->json(['success' => false, "message" => 'Provider doesnt have a primary address', 'errors' => null, 'data' => null], 200);
                    }
                    $order_address = $sp_address->address;
                } else {
                    $order_address = $location_of_service->address;
                }

                $new_order->service_provider_id = $listing_to_find->user_id;
                $new_order->listing_id = $listing_to_find->id;
            }

            $cgst = ($tour_to_find->pricing / 100) * 9;
            $sgst = ($tour_to_find->pricing / 100) * 9;

            $new_order->total = $tour_to_find->pricing + $cgst + $sgst;
            $new_order->save();

            $new_order_invoice = new OrderInvoice();
            $new_order_invoice->order_id = $new_order->id;
            $new_order_invoice->listing_order_summary_id = $new_order_summary->id;
            $new_order_invoice->total_invoice_amount = $new_order->total;
            $new_order_invoice->discounted_amount = 0;
            $new_order_invoice->tax = $cgst + $sgst;
            $new_order_invoice->paid_amount = $new_order->total;
            $new_order_invoice->status = 0;
            $new_order_invoice->save();

            $new_order_detail = new ListingOrderDetail();
            $new_order_detail->listing_orders_id = $new_order->id;
            $new_order_detail->listing_order_summary_id = $new_order_summary->id;
            $new_order_detail->listing_tour_id = $tour_to_find->id;
            $new_order_detail->service_type = 3;
            $new_order_detail->address_of_service = $order_address;
            $new_order_detail->amount = (float)$tour_to_find->pricing;
            $new_order_detail->total_price = (float)$new_order_detail->amount + $cgst + $sgst;
            $new_order_detail->save();
        }
        if ($paratransit_to_find) {
            $new_order->service_type = 4;
            $new_order->service_provider_id = $sp_id_array_by_paratransit[0][1];

            if ($paratransit_to_find) {
                $listing_to_find = Listing::where('listing_paratransit_id', $cart_contents[$key]->listing_paratransit_id)
                ->first();
                $paratransit_to_find = ListingParaTransit::find($listing_to_find->listing_paratransit_id);
                $location_of_service = ListingLocation::where('listing_paratransit_id', $paratransit_to_find->id)
                ->first();
                if (is_null($location_of_service)) {
                    $sp_address  = UserAddress::where('user_id', $paratransit_to_find->service_provider_id)->first();
                    $order_address = $sp_address->address;
                } else {
                    $order_address = $location_of_service->address;
                }

                $new_order->service_provider_id = $listing_to_find->user_id;
                $new_order->listing_id = $listing_to_find->id;
            }

            $cgst = ($paratransit_to_find->pricing / 100) * 9;
            $sgst = ($paratransit_to_find->pricing / 100) * 9;

            $new_order->total = $paratransit_to_find->pricing + $cgst + $sgst;
            $new_order->save();

            $new_order_invoice = new OrderInvoice();
            $new_order_invoice->order_id = $new_order->id;
            $new_order_invoice->listing_order_summary_id = $new_order_summary->id;
            $new_order_invoice->total_invoice_amount = $new_order->total;
            $new_order_invoice->discounted_amount = 0;
            $new_order_invoice->tax = $cgst + $sgst;
            $new_order_invoice->paid_amount = $new_order->total;
            $new_order_invoice->status = 0;
            $new_order_invoice->save();

            $new_order_detail = new ListingOrderDetail();
            $new_order_detail->listing_orders_id = $new_order->id;
            $new_order_detail->listing_order_summary_id = $new_order_summary->id;
            $new_order_detail->paratransit_customer_request_id = $cart_contents[$key]->pickup_request_id;
            $new_order_detail->service_type = $request->service_type ? $request->service_type : null;
            $new_order_detail->address_of_service = $order_address;
            $new_order_detail->amount = (float)$paratransit_to_find->pricing;
            $new_order_detail->total_price = (float)$new_order_detail->amount + $cgst + $sgst;
            $new_order_detail->save();
        }

        event(new OrderCreatedEmail($new_order)); // new order created event
        event(new OrderCreatedSMS($new_order)); // new order created event

        $service_to_find = null;
        $product_to_find = null;
        $tour_to_find = null;
        $paratransit_to_find = null;
    }

    
    $find_existing_cart = Cart::where('cart_user_id', Auth::id())->first();
    if (!is_null($find_existing_cart)) {
        $delresponse = CartItem::where('carts_id', $find_existing_cart->id)->delete();
    }

        // $orders_info_array =
    $deals_array = Discount::all();
    $order_with_deals = [
            // "deals"=>$deals_array,
        "order_info"=>$order_collection,
    ];

    return response()
    ->json(['success' => true, "message" => 'Order created successfully', 'data' => $order_with_deals, "errors" => null], 200);
}
}
