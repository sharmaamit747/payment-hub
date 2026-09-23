# Payment Abstraction Layer

The Payment Abstraction Layer provides a common interface for integrating
multiple payment providers.

## Responsibilities

- Create payments
- Check payment status
- Process refunds
- Handle provider webhooks
- Normalize provider responses

## Architecture

Application
    ↓
Payment Manager
    ↓
Payment Gateway Interface
    ↓
Provider Adapter
    ↓
Payment Provider