<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemAttribute;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function index()
    {
        $items = Item::latest()->paginate(10);

        return $items;
    }

    public function getItemAttributes()
    {
        try {
            $items = Item::all();
            $itemsWithAttributes = [];

            foreach ($items as $item) {
                $attributes = ItemAttribute::where('item_id', $item->id)->get();
                $itemWithAttributes = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'attributes' => $attributes,
                ];
                array_push($itemsWithAttributes, $itemWithAttributes);
            }

            return response()->json($itemsWithAttributes, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    public function search()
    {
        $searchQuery = request('query');
        $fields = Item::where(function ($query) use ($searchQuery) {
            $query->where('name', 'like', "%{$searchQuery}%")
                ->orWhere('serial', 'like', "%{$searchQuery}%")
                ->orWhere('status', 'like', "%{$searchQuery}%");
        })->paginate(10);

        return response()->json($fields);
    }

    public function destroy($id)
    {
        $items = Item::findOrFail($id);
        $items->delete();

        return response()->json($items);
    }

    public function getItems(Item $item)
    {
        return $item;
    }

    public function getAttributes($id)
    {
        $item = Item::find($id);

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }
        $attributes = $item->attributes()->get(['name', 'value']);

        $formattedAttributes = $attributes->map(function ($attribute) {
            return [
                'name' => $attribute->name,
                'value' => $attribute->value,
            ];
        });

        return response()->json($formattedAttributes, 200);
    }

    public function update(Request $request, $id)
    {
        $formData = $request->validate([
            'name' => 'required|string',
            'parent_id' => 'required',
            'model' => 'required',
            'price' => 'required|numeric',
            'mfg_date' => 'required',
            'serial' => 'required',
            'status' => 'required',
            'manufacturer' => 'required',
            'location' => 'required',
            'warranty' => 'max:30',
            'insurance' => 'max:30',
            'net_weight' => 'nullable|numeric',
            'value' => 'nullable|array',
            'value.*.name' => 'required|string',
            'value.*.value' => 'required|string',
        ], [
            'price.numeric' => 'Input only number w/ out comma, space, letter !',
            'net_weight.numeric' => 'Input only number in kg w/ out comma, space, letter !',
        ]);

        $item = Item::find($id);

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $duplicateNames = collect($formData['value'])
            ->groupBy('name')
            ->filter(function ($group) {
                return $group->count() > 1;
            })
            ->keys();

        if (!$duplicateNames->isEmpty()) {
            return response()->json(['error' => 'Duplicate attribute name  ' . $duplicateNames->implode(', ')], 400);
        }

        $item->name = $formData['name'];
        $item->serial = $formData['serial'];
        $item->status = $formData['status'];
        $item->model = $formData['model'];
        $item->price = $formData['price'];
        $item->mfg_date = $formData['mfg_date'];
        $item->parent_id = $formData['parent_id'];
        $item->manufacturer = $formData['manufacturer'];
        $item->location = $formData['location'];
        $item->warranty = $formData['warranty'];
        $item->net_weight = $formData['net_weight'];
        $item->insurance = $formData['insurance'];

        $item->save();

        $existingAttributeNames = $item->attributes()->pluck('name')->toArray();

        foreach ($formData['value'] as $fieldData) {
            $attribute = $item->attributes()->updateOrCreate(
                ['name' => $fieldData['name']],
                ['value' => $fieldData['value']]
            );

            $key = array_search($fieldData['name'], $existingAttributeNames);
            if ($key !== false) {
                unset($existingAttributeNames[$key]);
            }
        }

        $item->attributes()->whereIn('name', $existingAttributeNames)->delete();

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        $items = Item::where('parent_id', $id)->paginate(10);

        if ($items->isEmpty()) {
            return response()->json(['error' => 'No items found for the specified field group'], 404);
        }

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $formData = $request->validate([
            // 'category_id' => 'required|numeric',
            'parent_id' => 'required|numeric',
            'item_name' => 'required|string',
            'model' => 'required',
            'mfg_date' => 'required',
            'price' => 'required|numeric',
            'serial' => 'required|unique:items,serial',
            'status' => 'required',
            'manufacturer' => 'required',
            'location' => 'required',
            'warranty' => 'max:30',
            'insurance' => 'max:30',
            'net_weight' => 'nullable|numeric',
            'value' => 'required|array',
            'value.*.label' => 'required|string',
            'value.*.value' => 'required|string',
        ], [
            'parent_id.required' => 'Select sub category name is required !',
            'price.numeric' => 'Input only number w/ out comma, space, letter !',
            'net_weight.numeric' => 'Input only number in kg w/ out comma, space, letter !',
        ]);

        $number = mt_rand(1000000000, 9999999999);
        $request['barcode'] = $number;

        $item = Item::create([
            'name' => $formData['item_name'],
            'parent_id' => $formData['parent_id'],
            'model' => $formData['model'],
            'price' => $formData['price'],
            'mfg_date' => $formData['mfg_date'],
            'serial' => $formData['serial'],
            'status' => $formData['status'],
            'manufacturer' => $formData['manufacturer'],
            'location' => $formData['location'],
            'warranty' => $formData['warranty'],
            'insurance' => $formData['insurance'],
            'net_weight' => $formData['net_weight'],
            'barcode' => $number,
        ]);

        $itemAttributes = [];

        foreach ($formData['value'] as $fieldData) {
            $itemAttribute = ItemAttribute::create([
                'item_id' => $item->id,
                'name' => $fieldData['label'],
                'value' => $fieldData['value'],
            ]);

            $itemAttributes[] = $itemAttribute;
        }

        return response()->json(['item' => $item, 'attributes' => $itemAttributes], 201);
    }
}
