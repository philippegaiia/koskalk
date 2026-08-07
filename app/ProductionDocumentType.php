<?php

namespace App;

enum ProductionDocumentType: string
{
    case SupplierConfirmation = 'supplier_confirmation';
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case DeliveryNote = 'delivery_note';
    case CertificateOfAnalysis = 'certificate_of_analysis';
    case SafetyDataSheet = 'safety_data_sheet';
    case Specification = 'specification';
    case Certificate = 'certificate';
    case Photo = 'photo';
    case Journal = 'journal';
    case Other = 'other';
}
