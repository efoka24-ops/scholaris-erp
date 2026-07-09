/** Message générique à transmettre à un fournisseur de canal (§23.1 du guide). */
export interface ChannelMessage {
  /**
   * Adresse email, numéro de téléphone (E.164) ou identifiant utilisateur selon le canal
   * — résolu par CommunicationsService.resolveContact() avant l'appel au sender.
   */
  to: string;
  subject?: string;
  body: string;
}

export interface ChannelSendResult {
  providerMessageId?: string;
}

/** Adaptateur de canal — une implémentation par canal (§23.1), injectée dans CommunicationsService. */
export interface ChannelSender {
  send(message: ChannelMessage): Promise<ChannelSendResult>;
}
