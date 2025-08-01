<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Tickets",
 *     description="API Endpoints for managing support tickets"
 * )
 */
class TicketController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/tickets",
     *     summary="Create a new ticket",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id", "subject", "message"},
     *             @OA\Property(property="user_id", type="string", example="MC34234"),
     *             @OA\Property(property="subject", type="string", example="Account Issue"),
     *             @OA\Property(property="message", type="string", example="I cannot access my account")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Ticket created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Ticket created successfully"),
     *             @OA\Property(
     *                 property="ticket",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="subject", type="string", example="Account Issue"),
     *                 @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                 @OA\Property(property="status", type="string", example="open"),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,user_id',
            'subject' => 'required|string|max:255',
            'message' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ticket = Ticket::create([
            'user_id' => $request->user_id,
            'subject' => $request->subject,
            'message' => $request->message,
            'status' => Ticket::STATUS_OPEN,
        ]);

        return response()->json([
            'message' => 'Ticket created successfully',
            'ticket' => $ticket
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/tickets/user/{user_id}",
     *     summary="Get user's tickets",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of user's tickets",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="tickets",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                     @OA\Property(property="user_id", type="string", example="MC34234"),
     *                     @OA\Property(property="subject", type="string", example="Account Issue"),
     *                     @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                     @OA\Property(property="status", type="string", example="open"),
     *                     @OA\Property(property="admin_reply", type="string", nullable=true),
     *                     @OA\Property(property="admin_replied_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function getUserTickets($userId)
    {
        $tickets = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['tickets' => $tickets]);
    }

    /**
     * @OA\Get(
     *     path="/api/tickets/{ticket_id}",
     *     summary="Get ticket details",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="ticket_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ticket details",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="ticket",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="subject", type="string", example="Account Issue"),
     *                 @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                 @OA\Property(property="status", type="string", example="open"),
     *                 @OA\Property(property="admin_reply", type="string", nullable=true),
     *                 @OA\Property(property="admin_replied_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time"),
     *                 @OA\Property(
     *                     property="user",
     *                     type="object",
     *                     @OA\Property(property="user_id", type="string", example="MC34234"),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john@example.com")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ticket not found"
     *     )
     * )
     */
    public function show($ticketId)
    {
        $ticket = Ticket::with('user')->where('ticket_id', $ticketId)->firstOrFail();
        return response()->json(['ticket' => $ticket]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/tickets/{ticket_id}/reply",
     *     summary="Admin reply to ticket",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="ticket_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reply"},
     *             @OA\Property(property="reply", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Reply added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Reply added successfully"),
     *             @OA\Property(
     *                 property="ticket",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="subject", type="string", example="Account Issue"),
     *                 @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                 @OA\Property(property="status", type="string", example="closed"),
     *                 @OA\Property(property="admin_reply", type="string", example="We have resolved your issue"),
     *                 @OA\Property(property="admin_replied_at", type="string", format="date-time"),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ticket not found"
     *     )
     * )
     */
    public function adminReply(Request $request, $ticketId)
    {
        $validator = Validator::make($request->all(), [
            'reply' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();

        if (!$ticket->canBeReplied()) {
            return response()->json([
                'message' => 'Cannot reply to this ticket as it is ' . $ticket->status
            ], 400);
        }

        $ticket->update([
            'admin_reply' => $request->reply,
            'status' => Ticket::STATUS_CLOSE,
            'admin_replied_at' => now(),
        ]);

        return response()->json([
            'message' => 'Reply added successfully',
            'ticket' => $ticket
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/tickets/{ticket_id}/reply",
     *     summary="Delete admin reply from ticket",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="ticket_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Admin reply deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Admin reply deleted successfully"),
     *             @OA\Property(
     *                 property="ticket",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="subject", type="string", example="Account Issue"),
     *                 @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                 @OA\Property(property="status", type="string", example="open"),
     *                 @OA\Property(property="admin_reply", type="string", nullable=true),
     *                 @OA\Property(property="admin_replied_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ticket not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No admin reply to delete"
     *     )
     * )
     */
    public function deleteAdminReply($ticketId)
    {
        $ticket = Ticket::where('ticket_id', $ticketId)->firstOrFail();

        if (!$ticket->admin_reply) {
            return response()->json([
                'message' => 'No admin reply found for this ticket'
            ], 400);
        }

        $ticket->update([
            'admin_reply' => null,
            'admin_replied_at' => null,
            'status' => Ticket::STATUS_OPEN,
        ]);

        return response()->json([
            'message' => 'Admin reply deleted successfully',
            'ticket' => $ticket
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/tickets",
     *     summary="Get all tickets (admin only)",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", enum={"open", "close"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of all tickets",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="tickets",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                     @OA\Property(property="user_id", type="string", example="MC34234"),
     *                     @OA\Property(property="subject", type="string", example="Account Issue"),
     *                     @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                     @OA\Property(property="status", type="string", example="open"),
     *                     @OA\Property(property="admin_reply", type="string", nullable=true),
     *                     @OA\Property(property="admin_replied_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time"),
     *                     @OA\Property(
     *                         property="user",
     *                         type="object",
     *                         @OA\Property(property="user_id", type="string", example="MC34234"),
     *                         @OA\Property(property="name", type="string", example="John Doe"),
     *                         @OA\Property(property="email", type="string", example="john@example.com")
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function adminIndex(Request $request)
    {
        $query = Ticket::with('user');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['tickets' => $tickets]);
    }

    /**
     * @OA\Get(
     *     path="/api/tickets/by-id/{ticket_id}",
     *     summary="Get ticket by unique ticket ID",
     *     tags={"Tickets"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="ticket_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="geg432")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ticket details",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="ticket",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ticket_id", type="string", example="geg432"),
     *                 @OA\Property(property="user_id", type="string", example="MC34234"),
     *                 @OA\Property(property="subject", type="string", example="Account Issue"),
     *                 @OA\Property(property="message", type="string", example="I cannot access my account"),
     *                 @OA\Property(property="status", type="string", example="open"),
     *                 @OA\Property(property="admin_reply", type="string", nullable=true),
     *                 @OA\Property(property="admin_replied_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time"),
     *                 @OA\Property(
     *                     property="user",
     *                     type="object",
     *                     @OA\Property(property="user_id", type="string", example="MC34234"),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john@example.com")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Ticket not found"
     *     )
     * )
     */
    public function getByTicketId($ticketId)
    {
        $ticket = Ticket::with('user')->where('ticket_id', $ticketId)->firstOrFail();
        return response()->json(['ticket' => $ticket]);
    }
} 