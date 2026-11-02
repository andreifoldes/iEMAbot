import {DataStructure} from "../DataStructure";
import {Action} from "./Action";
import {EventTrigger} from "./EventTrigger";

export class ActionTrigger extends DataStructure {
	public actions							= this.objectArray(			"actions", Action)
	public eventTriggers					= this.objectArray(			"eventTriggers", EventTrigger)
}